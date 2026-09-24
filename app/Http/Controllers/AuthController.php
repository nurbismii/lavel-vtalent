<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\FormResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $data['email'] = Str::lower(trim($data['email']));
        if (! Auth::attempt([...$data, 'role' => Role::Candidate->value, 'active' => true], false)) {
            return back()->withErrors(['email' => 'Email atau password tidak sesuai.'])->onlyInput('email');
        }
        $user = $request->user();
        if ($user->must_change_password && $user->temporary_password_expires_at?->isPast()) {
            Auth::logout();

            return back()->withErrors(['email' => 'Password sementara kedaluwarsa. Hubungi HR untuk reset akses.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->route($user->must_change_password ? 'password.initial' : 'candidate.dashboard');
    }

    public function logout(Request $request): mixed
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function change(Request $request): mixed
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(12), 'different:current_password']]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            $user->update(['password' => $data['password'], 'must_change_password' => false, 'temporary_password_expires_at' => null, 'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLog::record('account.password_changed', $user, $user);
            if (Schema::hasTable('form_responses')) {
                FormResponse::where('user_id', $user->id)->where('activation_pending', true)->update(['activation_pending' => false]);
            }
        });
        $request->session()->regenerate();
        $request->session()->put('portal_session_version', $user->session_version);

        return redirect()->route('candidate.dashboard')->with('status', 'Password berhasil diperbarui.');
    }

    public function forgot(Request $request): mixed
    {
        $request->validate(['email' => 'required|email']);
        $email = Str::lower(trim($request->email));
        if (User::where('email', $email)->where('active', true)->where('role', Role::Candidate)->exists()) {
            try {
                Password::sendResetLink(['email' => $email, 'active' => true, 'role' => Role::Candidate->value]);
            } catch (\Throwable $exception) {
                Log::error('Pengiriman email reset password gagal.', [
                    'exception_type' => get_class($exception),
                    'mailer' => config('mail.default'),
                    'smtp_host' => config('mail.mailers.smtp.host'),
                    'smtp_port' => config('mail.mailers.smtp.port'),
                    'smtp_timeout' => config('mail.mailers.smtp.timeout'),
                ]);
            }
        }

        return back()->with('status', 'Permintaan reset diterima. Jika akun memenuhi syarat, periksa email Anda. Jika belum masuk, tunggu 60 detik sebelum mencoba kembali atau hubungi HR.');
    }

    public function reset(Request $request): mixed
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        $status = Password::reset([...$data, 'active' => true, 'role' => Role::Candidate->value], function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => $password, 'must_change_password' => false, 'temporary_password_expires_at' => null, 'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                AuditLog::record('account.password_reset', $user, $user);
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('status', 'Password diperbarui. Silakan masuk.') : back()->withErrors(['email' => 'Tautan reset tidak valid atau kedaluwarsa.']);
    }
}
