<?php

use App\Enums\Role;
use App\Models\FormAccessToken;
use App\Models\Position;
use App\Models\RecruitmentPeriod;
use App\Models\User;
use App\Services\CandidateFormService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_ends_with(config('database.connections.sqlite.database'), 'candidate-forms-browser.sqlite') || ! str_contains(storage_path(), 'forms-browser')) {
    throw new RuntimeException('Form browser fixtures require isolated database and storage.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$password = Str::password(24);
$admin = User::create(['name' => 'HR Form Browser', 'email' => 'forms.hr@example.test', 'password' => $password, 'role' => Role::Admin]);
$secret = (new Google2FA)->generateSecretKey();
$admin->saveAppAuthenticationSecret($secret);
$position = Position::create(['name' => 'Frontend Developer', 'active' => true]);
$period = RecruitmentPeriod::create(['name' => 'Rekrutmen September 2026', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'active' => true]);
$fields = [];
foreach (['text' => 'Domisili saat ini', 'date' => 'Tanggal lahir', 'select' => 'Pendidikan terakhir', 'file' => 'Curriculum Vitae'] as $type => $label) {
    $fields[] = ['id' => (string) Str::uuid(), 'type' => $type, 'label' => $label, 'help' => $type === 'file' ? 'Unggah CV terbaru dalam format PDF.' : '', 'placeholder' => '', 'required' => true, 'options' => ['Diploma', 'Sarjana'], 'max_length' => 255, 'min' => '', 'max' => '', 'max_mb' => 10, 'max_files' => 1, 'extensions' => ['pdf']];
}
$service = app(CandidateFormService::class);
$form = $service->saveTemplate($admin, null, 0, ['title' => 'Kenali potensi Anda', 'description' => 'Ceritakan pengalaman dan latar belakang Anda untuk bergabung dengan tim VDNi.', 'fields' => $fields]);
$version = $service->publish($admin, $form, $form->lock_version);
$intake = $service->createIntake($admin, ['candidate_form_version_id' => $version->id, 'position_id' => $position->id, 'recruitment_period_id' => $period->id, 'deadline' => now()->addDays(7)->toDateTimeString()]);
$raw = Str::random(64);
FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => 'forms.candidate@example.test', 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHour()]);
file_put_contents(storage_path('browser-fixtures.json'), json_encode(['admin' => $admin->email, 'password' => $password, 'secret' => $secret, 'slug' => $intake->slug, 'token' => $raw, 'fields' => $fields]));
echo "Isolated form fixtures ready.\n";
