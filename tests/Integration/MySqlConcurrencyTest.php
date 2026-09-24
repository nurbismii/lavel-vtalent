<?php

namespace Tests\Integration;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CandidateFormService;
use App\Services\SubmissionService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    private string $databaseName = '';

    private ?\PDO $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PORTAL_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Run with PORTAL_MYSQL_TESTS=1 against a local MySQL test server.');
        }
        $mysql = config('database.connections.mysql');
        $this->server = new \PDO('mysql:host='.$mysql['host'].';port='.$mysql['port'].';charset=utf8mb4', $mysql['username'], $mysql['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->databaseName = 'vtalent_ci_'.bin2hex(random_bytes(6));
        $this->server->exec('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4');
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $this->databaseName]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->server && preg_match('/^vtalent_ci_[a-f0-9]{12}$/', $this->databaseName)) {
            DB::disconnect('mysql');
            $this->server->exec('DROP DATABASE `'.$this->databaseName.'`');
            $root = storage_path('framework/testing/'.$this->databaseName);
            if (is_dir($root)) {
                (new Filesystem)->deleteDirectory($root);
            }
        }
        parent::tearDown();
    }

    private function parallel(string $action, Submission $submission, int $versionId, int $fileId = 0): array
    {
        $workers = [];
        $release = microtime(true) + 2;
        for ($i = 0; $i < 2; $i++) {
            $worker = new Process([PHP_BINARY, __DIR__.'/mysql-worker.php', $action, (string) $submission->id, (string) $versionId, (string) $fileId, (string) $release], base_path(), ['PORTAL_TEST_DATABASE' => $this->databaseName]);
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_parallel_final_requests_create_one_receipt_on_mysql(): void
    {
        $s = Submission::factory()->create();
        $u = $s->application->user;
        $v = app(SubmissionService::class)->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id]);
        $results = $this->parallel('final', $s, $v->id, $f->id);
        $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(1, $s->versions()->where('status', 'final')->count());
    }

    public function test_parallel_uploads_cannot_exceed_quota_on_mysql(): void
    {
        $s = Submission::factory()->create(['type' => 'technical_test']);
        Submission::factory()->create(['recruitment_application_id' => $s->recruitment_application_id, 'type' => 'portfolio', 'status' => 'exempt', 'administrative_reason' => 'Prasyarat pengujian kuota']);
        $v = app(SubmissionService::class)->draft($s, $s->application->user);
        AppSetting::create(['key' => 'quota_mb', 'value' => 1]);
        $results = $this->parallel('upload', $s, $v->id);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'accepted'));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'rejected'));
        $this->assertLessThanOrEqual(1048576, UploadedFile::where('recruitment_application_id', $s->recruitment_application_id)->sum('size'));
    }

    private function formResponses(bool $twoIntakes): array
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $service = app(CandidateFormService::class);
        $form = $service->saveTemplate($admin, null, 0, ['title' => 'MySQL form', 'description' => '', 'fields' => []]);
        $version = $service->publish($admin, $form, $form->lock_version);
        $scope = ['candidate_form_version_id' => $version->id, 'position_id' => Position::factory()->create()->id, 'recruitment_period_id' => RecruitmentPeriod::factory()->create()->id];
        $intake = $service->createIntake($admin, $scope);
        $response = $intake->responses()->create(['reference' => (string) Str::uuid(), 'name' => 'Kandidat', 'email' => 'mysql.forms@example.test', 'email_verified_at' => now(), 'answers' => []]);
        $other = $twoIntakes ? $service->createIntake($admin, $scope)->responses()->create(['reference' => (string) Str::uuid(), 'name' => 'Kandidat', 'email' => $response->email, 'email_verified_at' => now(), 'answers' => []]) : $response;

        return [$admin, $response, $other];
    }

    private function parallelForms(string $action, array $responses, int $adminId): array
    {
        $workers = [];
        $release = microtime(true) + 2;
        foreach ($responses as $response) {
            $worker = new Process([PHP_BINARY, __DIR__.'/form-mysql-worker.php', $action, (string) $response->id, (string) $adminId, (string) $release], base_path(), ['PORTAL_TEST_DATABASE' => $this->databaseName]);
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }

        return array_map(function ($worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());

            return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    }

    public function test_parallel_form_final_requests_create_one_revision_on_mysql(): void
    {
        [$admin, $response, $other] = $this->formResponses(false);
        $results = $this->parallelForms('final', [$response, $other], $admin->id);
        $this->assertSame($results[0]['reference'], $results[1]['reference']);
        $this->assertSame(1, $response->revisions()->count());
    }

    public function test_parallel_form_linking_by_email_creates_one_account_on_mysql(): void
    {
        [$admin, $response, $other] = $this->formResponses(true);
        foreach ([$response, $other] as $item) {
            app(CandidateFormService::class)->save($item, 0, ['name' => 'Kandidat', 'answers' => [], 'consent' => true], true);
        }
        $results = $this->parallelForms('link', [$response, $other], $admin->id);
        $this->assertSame($results[0]['user_id'], $results[1]['user_id']);
        $this->assertSame($results[0]['application_id'], $results[1]['application_id']);
        $this->assertSame(1, User::where('email', $response->email)->count());
        $this->assertSame(1, RecruitmentApplication::count());
    }
}
