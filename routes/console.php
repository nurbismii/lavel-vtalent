<?php

use App\Models\AccessDelivery;
use Illuminate\Support\Facades\Schedule;

Schedule::command('portal:cleanup')->daily()->withoutOverlapping();
Schedule::command('forms:cleanup')->daily()->withoutOverlapping();

Schedule::call(function (): void {
    AccessDelivery::whereNotNull('password')->where('expires_at', '<=', now())
        ->update(['password' => null, 'status' => 'cancelled']);
})->name('portal:expire-access-emails')->hourly()->withoutOverlapping();
