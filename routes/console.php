<?php

use App\Models\AccessDelivery;
use App\Models\PortfolioExport;
use Illuminate\Support\Facades\Schedule;

Schedule::command('portal:cleanup')->daily()->withoutOverlapping();
Schedule::command('forms:cleanup')->daily()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [PortfolioExport::class]])->hourly()->withoutOverlapping();

Schedule::call(function (): void {
    AccessDelivery::whereNotNull('password')->where('expires_at', '<=', now())
        ->update(['password' => null, 'status' => 'cancelled']);
})->name('portal:expire-access-emails')->hourly()->withoutOverlapping();

Schedule::command('psychometrics:finalize')->everyMinute()->withoutOverlapping();
