<?php

use App\Enums\Role;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_ends_with(config('database.connections.sqlite.database'), 'portal-browser.sqlite')) {
    throw new RuntimeException('Browser fixtures require the isolated testing database.');
}
Artisan::call('migrate', ['--force' => true]);
$password = Str::password(24);
$admin = User::create(['name' => 'Rina HR', 'email' => 'hr.browser@example.test', 'password' => $password, 'role' => Role::Admin]);
$secret = (new Google2FA)->generateSecretKey();
$admin->saveAppAuthenticationSecret($secret);
$user = User::create(['name' => 'Nadia Amelia', 'email' => 'nadia.browser@example.test', 'password' => $password]);
$position = Position::create(['name' => 'Frontend Developer']);
$period = RecruitmentPeriod::create(['name' => 'September 2026', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
$application = RecruitmentApplication::create(['user_id' => $user->id, 'active_user_id' => $user->id, 'position_id' => $position->id, 'recruitment_period_id' => $period->id]);
$portfolio = $application->submissions()->create(['type' => 'portfolio', 'deadline' => now()->addDays(3)]);
$application->submissions()->create(['type' => 'technical_test', 'deadline' => now()->addDays(5), 'task_label' => 'Implementasi antarmuka web', 'instructions' => 'Unggah hasil pekerjaan sesuai brief yang diberikan HR.']);
file_put_contents(storage_path('framework/testing/browser-credentials.json'), json_encode(['candidate' => $user->email, 'admin' => $admin->email, 'password' => $password, 'secret' => $secret, 'submission' => $portfolio->id, 'application' => $application->id]));
echo "Isolated browser fixtures ready.\n";
