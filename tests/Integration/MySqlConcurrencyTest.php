<?php

namespace Tests\Integration;

use App\Models\AppSetting;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Services\SubmissionService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $v = app(SubmissionService::class)->draft($s, $s->application->user);
        AppSetting::create(['key' => 'quota_mb', 'value' => 1]);
        $results = $this->parallel('upload', $s, $v->id);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'accepted'));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'rejected'));
        $this->assertLessThanOrEqual(1048576,UploadedFile::where('recruitment_application_id',$s->recruitment_application_id)->sum('size'));
    }
}
