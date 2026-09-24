<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Responses\FormRateLimitResponse;
use App\Models\FormResponse;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('portal-emails', fn () => Limit::perSecond(1, max(1, (int) config('mail.queue_interval_seconds')))->by('shared-mailer'));
        RateLimiter::for('forms-public', fn ($request) => Limit::perMinute(120)->by('forms-public:'.$request->ip())->response([FormRateLimitResponse::class, 'make']));
        RateLimiter::for('forms-email', fn ($request) => Limit::perMinute(5)->by('forms-email:'.$request->ip())->response([FormRateLimitResponse::class, 'make']));
        RateLimiter::for('forms-upload', fn ($request) => Limit::perMinute(15)->by('forms-upload:'.$request->ip())->response([FormRateLimitResponse::class, 'make']));
        RateLimiter::for('forms-verification', fn ($request) => Limit::perMinute(10)->by('forms-verification:'.$request->ip())->response([FormRateLimitResponse::class, 'make']));

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            return (new MailMessage)
                ->subject('Reset password akun V Talent')
                ->view(['html' => 'emails.auth.reset-password', 'text' => 'emails.auth.reset-password-text'], [
                    'name' => $user->name,
                    'resetUrl' => route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
                    'expiresIn' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                ]);
        });

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && request()->hasSession()) {
                session()->put('portal_session_version', $event->user->session_version);
            }
        });
        Event::listen(PasswordReset::class, function ($event): void {
            if (Schema::hasTable('form_responses')) {
                FormResponse::where('user_id', $event->user->id)->where('activation_pending', true)->update(['activation_pending' => false]);
            }
        });
        Livewire::addPersistentMiddleware([EnsurePortalAccess::class]);
    }
}
