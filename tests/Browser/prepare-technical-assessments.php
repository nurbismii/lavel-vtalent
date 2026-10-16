<?php

use App\Enums\Role;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\TechnicalAssessmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(basename(config('database.connections.sqlite.database')), 'technical-browser-')) {
    throw new RuntimeException('Use a dedicated technical-browser testing database.');
}
Artisan::call('migrate', ['--force' => true]);
$password = Str::password(24);
$admin = User::factory()->create(['role' => Role::Admin, 'password' => $password]);
$secret = (new Google2FA)->generateSecretKey();
$admin->saveAppAuthenticationSecret($secret);
$application = RecruitmentApplication::factory()->create();
$application->user->update(['password' => $password]);
$service = app(TechnicalAssessmentService::class);
$ids = [];
foreach (['A', 'B'] as $form) {
    $attempt = $service->assign($admin, ['application_id' => $application->id, 'form' => $form, 'opens_at' => now()->subMinute()->toDateTimeString(), 'deadline' => now()->addHours(3)->toDateTimeString(), 'durations' => array_column($service->packages()[$form]['sections'], 'seconds'), 'reviewed' => true]);
    $ids[$form] = $attempt->id;
}
echo json_encode(['candidate' => $application->user->email, 'admin' => $admin->email, 'password' => $password, 'secret' => $secret, 'attempts' => $ids], JSON_THROW_ON_ERROR);
