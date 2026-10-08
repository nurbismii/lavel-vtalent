<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Jobs\BuildPortfolioExport;
use App\Models\AuditLog;
use App\Models\PortfolioExport;
use App\Models\RecruitmentApplication;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\PortfolioExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class PortfolioExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $user->saveAppAuthenticationSecret('TESTSECRET');

        return $user;
    }

    private function attach(SubmissionVersion $version, string $purpose, string $content, string $scan = 'clean', bool $exists = true): void
    {
        $submission = $version->submission;
        $file = UploadedFile::factory()->create([
            'submission_id' => $submission->id,
            'recruitment_application_id' => $submission->recruitment_application_id,
            'uploader_id' => $submission->application->user_id,
            'purpose' => $purpose,
            'scan_status' => $scan,
            'original_name' => '../../Unsafe name.pdf',
        ]);
        if ($exists) {
            Storage::disk('private')->put($file->path, $content);
        }
        $version->attachments()->create(['uploaded_file_id' => $file->id, 'purpose' => $purpose]);
    }

    public function test_zip_contains_latest_final_safe_documents_and_relative_excel_links(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $first = RecruitmentApplication::factory()->create();
        $first->user->update(['name' => '=HYPERLINK("bad","bad")']);
        $second = RecruitmentApplication::factory()->create();
        $second->user->update(['name' => $first->user->name]);
        foreach ([$first, $second] as $application) {
            $submission = Submission::factory()->create(['recruitment_application_id' => $application->id, 'status' => 'revision']);
            $old = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'status' => 'final', 'number' => 1]);
            $final = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'status' => 'final', 'number' => 2, 'submitted_at' => now()]);
            $draft = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'number' => 3]);
            $this->attach($old, 'portfolio_main', 'old-final');
            $this->attach($final, 'portfolio_main', 'latest-final');
            $this->attach($final, 'portfolio_evidence', 'safe-evidence');
            $this->attach($final, 'portfolio_evidence', 'rejected', 'rejected');
            $this->attach($final, 'portfolio_evidence', 'pending', 'pending');
            $this->attach($final, 'portfolio_evidence', 'missing', exists: false);
            $this->attach($draft, 'portfolio_main', 'secret-draft');
        }
        $path = tempnam(sys_get_temp_dir(), 'portfolio-test-');
        $zip = new ZipArchive;
        $xlsxZip = new ZipArchive;
        $xlsxPath = $path.'.inspect.xlsx';
        $zipOpened = false;
        $xlsxOpened = false;
        try {
            $this->assertSame(2, app(PortfolioExportService::class)->write($admin, RecruitmentApplication::query()->whereKey([$first->id, $second->id])->with('user', 'position', 'period', 'submissions'), $path));
            $this->assertTrue($zipOpened = $zip->open($path) === true);
            $this->assertSame(7, $zip->numFiles);
            file_put_contents($xlsxPath, $zip->getFromName('Daftar-Kandidat.xlsx'));
            $this->assertTrue($xlsxOpened = $xlsxZip->open($xlsxPath) === true);
            $sheet = $xlsxZip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('HYPERLINK(&quot;Kandidat/', $sheet);
            $this->assertStringNotContainsString('/Portofolio.pdf', $sheet);
            $this->assertStringNotContainsString('<f>HYPERLINK(&quot;bad', $sheet);
            $this->assertStringContainsString('3 tidak tersedia', $sheet);
            $folders = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $this->assertStringNotContainsString('..', $entry);
                if (str_ends_with($entry, '.pdf') && ! str_contains($entry, '/Lampiran/')) {
                    $this->assertSame('latest-final', $zip->getFromIndex($i));
                    $this->assertSame(preg_replace('/-\d+$/', '', basename(dirname($entry))).'.pdf', basename($entry));
                    $folders[] = dirname($entry);
                } elseif (str_contains($entry, '/Lampiran/')) {
                    $this->assertSame('safe-evidence', $zip->getFromIndex($i));
                }
            }
            $this->assertCount(2, array_unique($folders));
            foreach ($folders as $folder) {
                $filename = preg_replace('/-\d+$/', '', basename($folder)).'.pdf';
                $this->assertStringContainsString($folder.'/'.$filename, $sheet);
                $this->assertStringContainsString($folder.'/&quot;', $sheet);
            }
            $this->assertDatabaseHas('audit_logs', ['action' => 'portfolio.offline_exported', 'actor_id' => $admin->id]);
            $this->assertFileDoesNotExist($path.'.xlsx');
        } finally {
            if ($zipOpened) {
                $zip->close();
            }
            if ($xlsxOpened) {
                $xlsxZip->close();
            }
            @unlink($path);
            @unlink($xlsxPath);
        }
    }

    public function test_page_export_uses_all_filters(): void
    {
        Storage::fake('private');
        Queue::fake();
        $application = RecruitmentApplication::factory()->create();
        $application->user->update(['name' => 'Export Match']);
        Submission::factory()->create(['recruitment_application_id' => $application->id, 'type' => 'portfolio', 'status' => 'draft', 'deadline' => now()->subDay()]);
        Submission::factory()->create(['recruitment_application_id' => $application->id, 'type' => 'technical_test', 'status' => 'not_started']);
        RecruitmentApplication::factory()->create();
        RecruitmentApplication::factory()->create([...$application->only('position_id', 'recruitment_period_id'), 'archived_at' => now()]);
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)
            ->set('search', 'Export Match')->set('positionFilter', (string) $application->position_id)
            ->set('periodFilter', (string) $application->recruitment_period_id)->set('portfolioFilter', 'draft')
            ->set('testFilter', 'not_started')->set('overdue', true)
            ->call('exportPortfolios')->assertHasNoErrors();
        $export = PortfolioExport::firstOrFail();
        $this->assertSame([$application->id], $export->application_ids);
        $this->assertSame('pending', $export->status);
        Queue::assertPushed(BuildPortfolioExport::class, fn ($job) => $job->exportId === $export->id && $job->connection === 'portfolio_exports' && $job->queue === 'exports');
        $page->call('exportPortfolios')->assertHasErrors('portfolioExport');
        $this->assertDatabaseCount('portfolio_exports', 1);
        (new BuildPortfolioExport($export->id))->handle(app(PortfolioExportService::class));
        $this->assertSame('ready', $export->fresh()->status);
        $page->set('archived', true)->call('exportPortfolios')->assertHasErrors('portfolioExport');
    }

    public function test_limits_and_empty_filter_fail_without_creating_archive(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        $submission = Submission::factory()->create(['recruitment_application_id' => $application->id]);
        $version = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'status' => 'final']);
        $this->attach($version, 'portfolio_main', 'too-large');
        $query = RecruitmentApplication::query()->with('user', 'position', 'period', 'submissions');
        $path = storage_path('framework/testing/blocked-export.zip');
        foreach (['empty', 'count'] as $case) {
            config(['submissions.portfolio_export.max_candidates' => $case === 'count' ? 0 : 100]);
            try {
                app(PortfolioExportService::class)->write($admin, $case === 'empty' ? (clone $query)->whereRaw('1 = 0') : $query, $path);
                $this->fail('Export must reject '.$case);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('portfolioExport', $exception->errors());
                $this->assertFileDoesNotExist($path);
            }
        }
    }

    public function test_candidate_cannot_export(): void
    {
        $this->expectException(HttpException::class);
        app(PortfolioExportService::class)->write(User::factory()->create(), RecruitmentApplication::query(), storage_path('framework/testing/forbidden.zip'));
    }

    public function test_queued_export_over_50_mb_can_be_downloaded_only_by_owner_and_is_pruned(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        $submission = Submission::factory()->create(['recruitment_application_id' => $application->id]);
        $version = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'status' => 'final']);
        $this->attach($version, 'portfolio_main', '%PDF');
        $file = $version->attachments()->first()->file;
        $handle = fopen(Storage::disk('private')->path($file->path), 'c+');
        ftruncate($handle, 51 * 1024 * 1024);
        fclose($handle);
        $export = PortfolioExport::create(['actor_id' => $admin->id, 'application_ids' => [$application->id], 'path' => 'exports/test-large.zip', 'expires_at' => now()->addDay()]);
        $url = route('portfolio-exports.download', $export);
        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])->get($url)->assertNotFound();
        config(['queue.connections.portfolio_exports.after_commit' => false]);
        BuildPortfolioExport::dispatch($export->id);
        $this->assertDatabaseHas('jobs', ['queue' => 'exports']);
        $this->artisan('queue:work', ['connection' => 'portfolio_exports', '--queue' => 'exports', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('jobs', ['queue' => 'exports']);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portfolio.offline_exported']);
        $audit = AuditLog::where('action', 'portfolio.offline_exported')->firstOrFail();
        $this->assertGreaterThan(50 * 1024 * 1024, $audit->metadata['bytes']);
        $response = $this->get($url)->assertOk()->assertDownload('portofolio-'.$export->id.'.zip');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $other = $this->admin();
        $this->actingAs($other)->withSession(['portal_session_version' => $other->session_version])->get($url)->assertForbidden();
        $candidate = $application->user;
        $this->actingAs($candidate)->withSession(['portal_session_version' => $candidate->session_version])->get($url)->assertForbidden();
        $export->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])->get($url)->assertNotFound();
        $this->artisan('model:prune', ['--model' => [PortfolioExport::class]])->assertSuccessful();
        Storage::disk('private')->assertMissing($export->path);
        $this->assertDatabaseMissing('portfolio_exports', ['id' => $export->id]);
    }

    public function test_revoked_admin_access_fails_job_and_removes_partial_files(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        $export = PortfolioExport::create(['actor_id' => $admin->id, 'application_ids' => [$application->id], 'path' => 'exports/failed.zip', 'expires_at' => now()->addDay()]);
        Storage::disk('private')->put($export->path, 'partial');
        Storage::disk('private')->put($export->path.'.xlsx', 'partial');
        $admin->update(['active' => false]);
        try {
            (new BuildPortfolioExport($export->id))->handle(app(PortfolioExportService::class));
            $this->fail('Revoked access must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('failed', $export->fresh()->status);
        Storage::disk('private')->assertMissing($export->path);
        Storage::disk('private')->assertMissing($export->path.'.xlsx');
    }
}
