<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Jobs\BuildPortfolioExport;
use App\Jobs\ScanFormDocument;
use App\Jobs\ScanUploadedFile;
use App\Jobs\SendCandidateAccess;
use App\Models\AccessDelivery;
use App\Models\AuditLog;
use App\Models\CandidateProfile;
use App\Models\FormAccessToken;
use App\Models\FormAccountDispatch;
use App\Models\FormResponse;
use App\Models\PortfolioExport;
use App\Models\PsychometricAttempt;
use App\Models\RecruitmentApplication;
use App\Models\Submission;
use App\Models\SubmissionAttachment;
use App\Models\SubmissionVersion;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CandidateFormService;
use App\Services\PortfolioExportService;
use App\Services\RecruitmentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CandidateDeactivationTest extends TestCase
{
    use DatabaseTruncation;

    /** Test real commits on a fresh in-memory database without running the protected rollback migrations. */
    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    private function formResponse(User $admin, RecruitmentApplication $application): FormResponse
    {
        $service = app(CandidateFormService::class);
        $form = $service->saveTemplate($admin, null, 0, ['title' => 'Form kandidat', 'fields' => []]);
        $version = $service->publish($admin, $form, $form->lock_version);
        $intake = $service->createIntake($admin, ['candidate_form_version_id' => $version->id, 'position_id' => $application->position_id, 'recruitment_period_id' => $application->recruitment_period_id]);

        return $intake->responses()->create(['reference' => (string) Str::uuid(), 'email' => $application->user->email, 'name' => $application->user->name, 'email_verified_at' => now(), 'answers' => ['history' => 'Jawaban tetap disimpan'], 'status' => 'submitted', 'submitted_at' => now(), 'user_id' => $application->user_id, 'recruitment_application_id' => $application->id]);
    }

    public function test_deactivation_removes_all_file_versions_and_exports_but_preserves_history_and_other_candidates(): void
    {
        Storage::fake('private');
        Mail::fake();
        $admin = $this->admin();
        $file = UploadedFile::factory()->create();
        $submission = $file->submission;
        $application = $submission->application;
        $user = $application->user;
        $profile = CandidateProfile::factory()->create(['user_id' => $user->id, 'phone' => '08123456789']);
        $version = SubmissionVersion::factory()->create(['submission_id' => $submission->id, 'status' => 'final', 'notes' => 'Histori portofolio']);
        $attachment = SubmissionAttachment::factory()->create(['submission_version_id' => $version->id, 'uploaded_file_id' => $file->id]);
        $submission->update(['current_version_id' => $version->id]);
        $archived = RecruitmentApplication::factory()->create(['user_id' => $user->id, 'active_user_id' => null, 'archived_at' => now()]);
        $oldSubmission = Submission::factory()->create(['recruitment_application_id' => $archived->id]);
        $oldFile = UploadedFile::factory()->create(['submission_id' => $oldSubmission->id, 'recruitment_application_id' => $archived->id, 'uploader_id' => $user->id]);
        $other = UploadedFile::factory()->create();
        foreach ([$file, $oldFile, $other] as $upload) {
            Storage::disk('private')->put($upload->path, 'file contents');
        }
        $response = $this->formResponse($admin, $application);
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/selected.pdf', 'original_name' => 'selected.pdf', 'mime' => 'application/pdf', 'size' => 20, 'scan_status' => 'clean']);
        $released = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/released.pdf', 'original_name' => 'released.pdf', 'mime' => 'application/pdf', 'size' => 20, 'scan_status' => 'pending', 'selected' => false]);
        Storage::disk('private')->put($document->path, 'document');
        Storage::disk('private')->put($released->path, 'document');
        $revision = $response->revisions()->create(['number' => 1, 'name' => $user->name, 'answers' => $response->answers, 'document_ids' => [$document->id, $released->id], 'submitted_at' => now()]);
        $psychometric = PsychometricAttempt::factory()->create(['recruitment_application_id' => $application->id]);
        $technical = TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $application->id]);
        $export = PortfolioExport::create(['actor_id' => $admin->id, 'application_ids' => [$application->id, $other->recruitment_application_id], 'status' => 'ready', 'path' => 'exports/shared.zip', 'candidate_count' => 2, 'expires_at' => now()->addDay()]);
        Storage::disk('private')->put($export->path, 'zip copy');
        Storage::disk('private')->put($export->path.'.xlsx', 'temporary excel');
        $delivery = AccessDelivery::create(['user_id' => $user->id, 'email' => $user->email, 'password' => 'temporary-secret', 'message' => 'Akses kandidat', 'expires_at' => now()->addDay(), 'status' => 'pending']);
        $token = FormAccessToken::create(['form_intake_id' => $response->form_intake_id, 'email' => $user->email, 'token_hash' => hash('sha256', 'access'), 'expires_at' => now()->addDay()]);
        DB::table('sessions')->insert(['id' => 'candidate-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'reset', 'created_at' => now()]);

        Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $application->id)
            ->assertSee('Nonaktifkan & hapus file', false)->call('confirmCandidateDeactivation', $user->id)
            ->assertSee('4 file unggahan')->assertSee('2 lamaran')->assertSee('hasil tes, dan histori tetap disimpan')
            ->set('deactivationEmail', $user->email)->set('reason', 'Kandidat tidak melanjutkan proses')
            ->call('deactivateCandidate')->assertHasNoErrors()->assertSet('candidateDeactivation', [])->assertSet('cleanupAuditId', null)
            ->assertSee('seluruh file terkait berhasil dihapus');

        $this->assertFalse($user->fresh()->active);
        $this->assertSame(2, $user->fresh()->session_version);
        foreach ([$profile, $application, $archived, $version, $attachment, $revision, $psychometric, $technical, $delivery, $token, $export, $other] as $record) {
            $this->assertModelExists($record);
        }
        $this->assertSame('Histori portofolio', $version->fresh()->notes);
        $this->assertSame(['history' => 'Jawaban tetap disimpan'], $response->fresh()->answers);
        $this->assertSame('rejected', $file->fresh()->scan_status->value);
        $this->assertSame(0, $file->fresh()->size);
        $this->assertSame('rejected', $document->fresh()->scan_status);
        $this->assertFalse($document->fresh()->selected);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->password);
        $this->assertTrue($token->fresh()->expires_at->lte(now()));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        foreach ([$file->path, $oldFile->path, $document->path, $released->path, $export->path, $export->path.'.xlsx'] as $path) {
            Storage::disk('private')->assertMissing($path);
        }
        Storage::disk('private')->assertExists($other->path);
        $this->assertTrue($other->submission->application->user->fresh()->active);
        $this->assertSame('completed', AuditLog::where('action', 'candidate.deactivated')->sole()->metadata['cleanup_status']);
        (new SendCandidateAccess($delivery->id))->handle();
        (new ScanUploadedFile($file->id))->handle();
        (new ScanFormDocument($released->id))->handle();
        (new BuildPortfolioExport($export->id))->handle(app(PortfolioExportService::class));
        Mail::assertNothingSent();
        $this->assertSame('rejected', $released->fresh()->scan_status);
        $this->assertSame('failed', $export->fresh()->status);
    }

    public function test_confirmation_requires_matching_email_and_reason_and_can_be_cancelled(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('deactivateCandidate')->assertStatus(422);
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $user->id)
            ->call('deactivateCandidate')->assertHasErrors(['deactivationEmail', 'reason']);
        $page->set('deactivationEmail', 'someone-else@example.test')->set('reason', 'Tidak melanjutkan')->call('deactivateCandidate')->assertHasErrors('deactivationEmail');
        $this->assertTrue($user->fresh()->active);
        $page->call('cancelCandidateDeactivation')->assertSet('candidateDeactivation', []);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'candidate.deactivated']);
    }

    public function test_candidate_without_application_can_be_deactivated_without_removing_profile(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $profile = CandidateProfile::factory()->create(['user_id' => $user->id]);
        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')->set('candidateMode', 'existing')
            ->set('existingCandidateIds', [(string) $user->id])->call('confirmCandidateDeactivation', $user->id)
            ->set('deactivationEmail', $user->email)->set('reason', 'Tidak melanjutkan rekrutmen')->call('deactivateCandidate')->assertHasNoErrors();
        $this->assertFalse($user->fresh()->active);
        $this->assertModelExists($profile);
    }

    #[TestWith(['candidate'])]
    #[TestWith(['inactive'])]
    #[TestWith(['missing_mfa'])]
    #[TestWith(['password_change'])]
    public function test_deactivation_rejects_unauthorized_actors(string $case): void
    {
        $actor = User::factory()->create(['role' => $case === 'candidate' ? Role::Candidate : Role::Admin, 'active' => $case !== 'inactive', 'must_change_password' => $case === 'password_change']);
        if ($case !== 'missing_mfa') {
            $actor->saveAppAuthenticationSecret('TESTSECRET');
        }
        $target = User::factory()->create();
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(RecruitmentService::class)->candidateDeactivationSummary($actor, $target->id);
    }

    #[TestWith(['active', false])]
    #[TestWith(['role', 'candidate'])]
    #[TestWith(['must_change_password', true])]
    public function test_deactivation_cannot_target_an_admin_and_rechecks_revoked_permissions(string $property, bool|string $value): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $otherAdmin->id)->assertForbidden();
        $user = User::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $user->id)
            ->set('deactivationEmail', $user->email)->set('reason', 'Tidak melanjutkan');
        $admin->update([$property => $value]);
        $page->call('deactivateCandidate')->assertForbidden();
        $this->assertTrue($user->fresh()->active);
    }

    public function test_unlinked_form_files_for_the_same_verified_email_are_removed_without_touching_other_responses(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        $response = $this->formResponse($admin, $application);
        $response->update(['user_id' => null, 'recruitment_application_id' => null]);
        $other = $response->intake->responses()->create(['reference' => (string) Str::uuid(), 'email' => 'another-candidate@example.test', 'email_verified_at' => now(), 'answers' => []]);
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/unlinked.pdf', 'original_name' => 'unlinked.pdf', 'mime' => 'application/pdf', 'size' => 10]);
        $otherDocument = $other->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/other.pdf', 'original_name' => 'other.pdf', 'mime' => 'application/pdf', 'size' => 10]);
        Storage::disk('private')->put($document->path, 'own file');
        Storage::disk('private')->put($otherDocument->path, 'other file');
        $service = app(RecruitmentService::class);
        $service->deactivateCandidate($admin, $application->user_id, $service->candidateDeactivationSummary($admin, $application->user_id), 'Tidak melanjutkan');
        Storage::disk('private')->assertMissing($document->path);
        Storage::disk('private')->assertExists($otherDocument->path);
        $this->assertSame(2, $response->fresh()->access_generation);
        $this->assertSame(1, $other->fresh()->access_generation);
        $this->assertSame(['history' => 'Jawaban tetap disimpan'], $response->fresh()->answers);
        $this->assertTrue($otherDocument->fresh()->selected);
        $this->assertModelExists($document);
    }

    public function test_new_upload_after_confirmation_blocks_deactivation_and_preserves_files(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $file = UploadedFile::factory()->create();
        $user = $file->submission->application->user;
        Storage::disk('private')->put($file->path, 'original');
        $service = app(RecruitmentService::class);
        $summary = $service->candidateDeactivationSummary($admin, $user->id);
        UploadedFile::factory()->create(['submission_id' => $file->submission_id, 'recruitment_application_id' => $file->recruitment_application_id, 'uploader_id' => $user->id]);
        try {
            $service->deactivateCandidate($admin, $user->id, $summary, 'Tidak melanjutkan');
            $this->fail('Perubahan unggahan harus membatalkan konfirmasi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidateDeactivation', $exception->errors());
        }
        $this->assertTrue($user->fresh()->active);
        Storage::disk('private')->assertExists($file->path);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'candidate.deactivated']);
    }

    public function test_running_export_blocks_file_removal(): void
    {
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        PortfolioExport::create(['actor_id' => $admin->id, 'application_ids' => [$application->id], 'status' => 'processing', 'path' => 'exports/processing.zip', 'candidate_count' => 1, 'expires_at' => now()->addDay()]);
        Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $application->user_id)
            ->set('deactivationEmail', $application->user->email)->set('reason', 'Tidak melanjutkan')
            ->call('deactivateCandidate')->assertHasErrors('candidateDeactivation')->assertSee('sedang dibuat');
        $this->assertTrue($application->user->fresh()->active);
    }

    public function test_running_account_link_blocks_deactivation(): void
    {
        $admin = $this->admin();
        $application = RecruitmentApplication::factory()->create();
        $response = $this->formResponse($admin, $application);
        FormAccountDispatch::create(['actor_id' => $admin->id, 'form_response_id' => $response->id, 'application_data' => [], 'status' => 'pending']);
        Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $application->user_id)
            ->set('deactivationEmail', $application->user->email)->set('reason', 'Tidak melanjutkan')
            ->call('deactivateCandidate')->assertHasErrors('candidateDeactivation')->assertSee('Penghubungan akun masih diproses');
        $this->assertTrue($application->user->fresh()->active);
    }

    public function test_outer_transaction_rollback_keeps_account_file_metadata_and_physical_files(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $file = UploadedFile::factory()->create();
        $user = $file->submission->application->user;
        Storage::disk('private')->put($file->path, 'original');
        $service = app(RecruitmentService::class);
        DB::beginTransaction();
        try {
            $audit = $service->deactivateCandidate($admin, $user->id, $service->candidateDeactivationSummary($admin, $user->id), 'Tidak melanjutkan');
            $this->assertFalse($user->fresh()->active);
            Storage::disk('private')->assertExists($file->path);
        } finally {
            DB::rollBack();
        }
        $this->assertTrue($user->fresh()->active);
        $this->assertSame('clean', $file->fresh()->scan_status->value);
        $this->assertSame(1024, $file->fresh()->size);
        $this->assertModelMissing($audit);
        Storage::disk('private')->assertExists($file->path);
    }

    public function test_failed_file_cleanup_is_reported_and_can_be_retried_from_the_ui(): void
    {
        Storage::fake('private');
        $manager = Storage::getFacadeRoot();
        Exceptions::fake();
        $admin = $this->admin();
        $file = UploadedFile::factory()->create();
        $user = $file->submission->application->user;
        Storage::disk('private')->put($file->path, 'original');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($file->path)->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with($file->path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('confirmCandidateDeactivation', $user->id)
            ->set('deactivationEmail', $user->email)->set('reason', 'Tidak melanjutkan')->call('deactivateCandidate')
            ->assertHasNoErrors()->assertSee('Sebagian file belum berhasil dibersihkan')->assertSee('Coba bersihkan file kembali');
        $audit = AuditLog::where('action', 'candidate.deactivated')->sole();
        $this->assertSame('pending', $audit->metadata['cleanup_status']);
        $this->assertCount(1, $audit->metadata['remaining_files']);
        $this->assertFalse($user->fresh()->active);
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => str_contains($exception->getMessage(), $file->path));
        Storage::swap($manager);
        Livewire::actingAs($admin)->test(Recruitment::class)->assertSet('cleanupAuditId', $audit->id)
            ->call('retryCandidateCleanup')->assertHasNoErrors()->assertSet('cleanupAuditId', null)->assertSee('Seluruh file terkait berhasil dibersihkan');
        Storage::disk('private')->assertMissing($file->path);
        $this->assertSame('completed', $audit->fresh()->metadata['cleanup_status']);
    }

    public function test_scheduled_cleanup_retries_pending_files_and_dry_run_preserves_them(): void
    {
        Storage::fake('private');
        $user = User::factory()->create(['active' => false]);
        $audit = AuditLog::record('candidate.deactivated', $user, $this->admin(), 'Tidak melanjutkan', ['cleanup_status' => 'pending', 'remaining_files' => [['disk' => 'private', 'path' => 'quarantine/pending-removal.pdf']]]);
        Storage::disk('private')->put('quarantine/pending-removal.pdf', 'original');
        $this->artisan('portal:cleanup', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('private')->assertExists('quarantine/pending-removal.pdf');
        $this->assertSame('pending', $audit->fresh()->metadata['cleanup_status']);
        $this->artisan('portal:cleanup')->assertSuccessful();
        Storage::disk('private')->assertMissing('quarantine/pending-removal.pdf');
        $this->assertSame('completed', $audit->fresh()->metadata['cleanup_status']);
    }
}
