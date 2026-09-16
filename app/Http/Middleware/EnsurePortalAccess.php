<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        $user->refresh();
        if (! $user->active || $request->session()->get('portal_session_version') !== $user->session_version || ($user->must_change_password && $user->temporary_password_expires_at?->isPast())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akses berakhir atau direset. Silakan masuk kembali atau hubungi HR.']);
        }
        if ($user->must_change_password && ! $request->routeIs('password.initial', 'password.initial.store', 'logout')) {
            return redirect()->route('password.initial');
        }

        return $next($request);
    }
}
