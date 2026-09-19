<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePortalAccess;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('portal-emails', fn () => Limit::perSecond(1, max(1, (int) config('mail.queue_interval_seconds')))->by('shared-mailer'));

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
        Livewire::addPersistentMiddleware([EnsurePortalAccess::class]);
    }
}
