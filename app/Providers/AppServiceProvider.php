<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePortalAccess;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && request()->hasSession()) {
                session()->put('portal_session_version', $event->user->session_version);
            }
        });
        Livewire::addPersistentMiddleware([EnsurePortalAccess::class]);
    }
}
