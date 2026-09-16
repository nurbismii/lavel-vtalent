<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ScanUploadedFile;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\AppSetting;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Services\SubmissionService;
use App\Services\UploadService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as HttpFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;
use Livewire\Livewire;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_without_scanning_is_saved_and_can_be_submitted_without_a_scan_job(): void
    {
        config(['submissions.scan_enabled' => false]);
        Storage::fake('private');
        Queue::fake();
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $file = app(UploadService::class)->store($submission, $user, HttpFile::fake()->create('portfolio.pdf', 100, 'application/pdf'), 'portfolio_main');

        $this->assertSame('skipped', $file->scan_status->value);
        Storage::disk('private')->assertExists($file->path);
        Queue::assertNothingPushed();

        $service = app(SubmissionService::class);
        $draft = $service->draft($submission, $user);
        $version = $service->save($submission, $user, $draft->id, ['notes' => '', 'links' => [], 'attachments' => [['id' => $file->id, 'description' => '']]], true);
        $this->assertSame('final', $version->status);
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get(route('files.download', $version->attachments()->first()))->assertOk();

        config(['submissions.scan_enabled' => true]);
        $this->get(route('files.download', $version->attachments()->first()))->assertNotFound();
    }

    public function test_existing_waiting_files_are_available_without_scanning_but_rejected_files_stay_blocked(): void
    {
        config(['submissions.scan_enabled' => false]);
        foreach ([ScanStatus::Pending, ScanStatus::Failed, ScanStatus::Skipped] as $status) {
            $this->assertTrue($status->available());
            $this->assertSame('Tersimpan', $status->label());
        }
        $this->assertFalse(ScanStatus::Rejected->available());
        $file = UploadedFile::factory()->create(['scan_status' => 'pending']);
        (new ScanUploadedFile($file->id))->handle();
        $this->assertSame('skipped', $file->fresh()->scan_status->value);
    }

    public function test_macro_document_disguised_as_docx_is_rejected(): void
    {
        Storage::fake('private');
        Queue::fake();
        $submission = Submission::factory()->create();
        $zipPath = Storage::disk('private')->path('macro-test.zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types>macroEnabled</Types>');
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();
        $file = HttpFile::fake()->createWithContent('portfolio.docx', file_get_contents($zipPath));
        $this->expectException(ValidationException::class);
        app(UploadService::class)->store($submission, $submission->application->user, $file, 'portfolio_evidence');
    }

    public function test_main_portfolio_rejects_word_even_with_legacy_upload_settings(): void
    {
        Storage::fake('private');
        Queue::fake();
        $limits = config('submissions.uploads');
        $limits['portfolio_main']['extensions'] = ['pdf', 'docx'];
        $limits['portfolio_main']['max_mb'] = 15;
        AppSetting::create(['key' => 'uploads', 'value' => $limits]);
        $submission = Submission::factory()->create();

        $this->assertSame(15, AppSetting::valueFor('uploads')['portfolio_main']['max_mb']);
        foreach ([['portfolio.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], ['portfolio.pdf', 'text/html']] as [$name, $mime]) {
            try {
                app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create($name, 1, $mime), 'portfolio_main');
                $this->fail('A non-PDF main portfolio must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('upload', $exception->errors());
            }
        }
        $this->assertDatabaseCount('uploaded_files', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
        Queue::assertNothingPushed();
        Livewire::actingAs($submission->application->user)
            ->test(SubmissionForm::class, ['submission' => $submission])
            ->assertSee('accept=".pdf"', false);
    }

    public function test_old_word_format_and_mime_mismatch_are_rejected(): void
    {
        $submission = Submission::factory()->create();
        foreach ([['old.doc', 'application/msword'], ['fake.pdf', 'text/html']] as [$name, $mime]) {
            try {
                app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create($name, 1, $mime), 'portfolio_main');
                $this->fail('Invalid file accepted');
            } catch (ValidationException) {
                $this->assertSame(0, UploadedFile::count());
            }
        }
    }

    public function test_upload_is_private_and_queued_for_scanning(): void
    {
        Storage::fake('private');
        Queue::fake();
        $submission = Submission::factory()->create();
        $file = app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create('portfolio.pdf', 100, 'application/pdf'), 'portfolio_main');
        $this->assertSame('pending', $file->scan_status->value);
        Storage::disk('private')->assertExists($file->path);
        Queue::assertPushed(ScanUploadedFile::class);
    }

    public function test_storage_failure_returns_upload_error_without_creating_record_or_job(): void
    {
        Queue::fake();
        $submission = Submission::factory()->create();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(
            UnableToWriteFile::atLocation('quarantine/test.pdf')
        );
        Storage::shouldReceive('disk')->with('private')->once()->andReturn($disk);

        try {
            app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create('portfolio.pdf', 100, 'application/pdf'), 'portfolio_main');
            $this->fail('Storage failure must produce a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('upload', $exception->errors());
            $this->assertSame(0, UploadedFile::count());
            Queue::assertNothingPushed();
        }
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        $submission = Submission::factory()->create();
        $this->expectException(ValidationException::class);
        app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create('payload.php', 1, 'application/pdf'), 'portfolio_main');
    }

    public function test_quota_counts_all_physical_history_files(): void
    {
        Storage::fake('private');
        Queue::fake();
        $submission = Submission::factory()->create();
        AppSetting::create(['key' => 'quota_mb', 'value' => 1]);
        UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $submission->application->user_id, 'size' => 1024 * 1024]);
        $this->expectException(ValidationException::class);
        app(UploadService::class)->store($submission, $submission->application->user, HttpFile::fake()->create('portfolio.pdf', 1, 'application/pdf'), 'portfolio_main');
    }

    public function test_missing_scanner_fails_closed(): void
    {
        Storage::fake('private');
        $file = UploadedFile::factory()->create(['scan_status' => 'pending']);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        config(['submissions.scanner_binary' => 'nonexistent-scanner-portal-test']);
        (new ScanUploadedFile($file->id))->handle();
        $this->assertSame('failed', $file->fresh()->scan_status->value);
    }
}
