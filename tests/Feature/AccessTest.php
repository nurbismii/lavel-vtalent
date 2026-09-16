<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\RecruitmentService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_absent(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_login_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('VDNi');
        $this->get('/admin/login')->assertOk();
        $this->get('/privacy')->assertOk();
    }

    public function test_temporary_password_requires_change(): void
    {
        $user = User::factory()->create(['password' => 'Temporary-password-123', 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporary-password-123'])->assertRedirect(route('password.initial'));
        $this->get('/portal')->assertRedirect(route('password.initial'));
        $this->post('/change-password', ['current_password' => 'Temporary-password-123', 'password' => 'A-new-long-password', 'password_confirmation' => 'A-new-long-password'])->assertRedirect(route('candidate.dashboard'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/portal')->assertOk();
    }

    public function test_candidate_cannot_access_other_candidate_submission(): void
    {
        $submission = Submission::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['portal_session_version' => 1])->get(route('candidate.submission', $submission))->assertForbidden();
    }

    public function test_reset_invalidates_existing_session(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($user)->withSession(['portal_session_version' => 1]);
        app(RecruitmentService::class)->resetAccess($admin, $user, 'Kandidat lupa akses');
        $this->get('/portal')->assertRedirect(route('login'));
    }

    public function test_candidate_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get('/admin/recruitment')->assertForbidden();
    }

    public function test_candidate_component_renders_and_saves_draft(): void
    {
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        Livewire::actingAs($user)->test(SubmissionForm::class, ['submission' => $submission])->assertSee('Dokumen utama')->set('notes', 'Catatan kandidat')->call('save')->assertHasNoErrors()->assertSee('Draf berhasil disimpan');
    }

    public function test_candidate_can_download_own_clean_file_but_other_candidate_cannot(): void
    {
        Queue::fake();
        Storage::fake('private');
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id]);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        $draft = app(SubmissionService::class)->draft($submission, $user);
        app(SubmissionService::class)->save($submission, $user, $draft->id, ['notes' => '', 'links' => [], 'attachments' => [['id' => $file->id, 'description' => '']]]);
        $attachment = $draft->attachments()->first();
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get(route('files.download', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['portal_session_version' => 1])->get(route('files.download', $attachment))->assertNotFound();
        $file->update(['scan_status' => 'pending']);
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get(route('files.download', $attachment))->assertNotFound();
    }

    public function test_admin_cannot_download_draft_even_with_mfa(): void
    {
        Storage::fake('private');
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $draft = app(SubmissionService::class)->draft($submission, $user);
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id]);
        $attachment = $draft->attachments()->create(['uploaded_file_id' => $file->id, 'purpose' => 'portfolio_main']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $this->actingAs($admin)->withSession(['portal_session_version' => 1])->get(route('files.download', $attachment))->assertNotFound();
        $this->get(route('files.preview', $attachment))->assertNotFound();
    }

    public function test_preview_serves_clean_pdf_inline_and_enforces_access_and_scan_status(): void
    {
        Storage::fake('private');
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $draft = app(SubmissionService::class)->draft($submission, $user);
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id]);
        Storage::disk('private')->put($file->path, "%PDF-1.4\n%%EOF");
        $attachment = $draft->attachments()->create(['uploaded_file_id' => $file->id, 'purpose' => 'portfolio_main']);
        $url = route('files.preview', $attachment);
        $this->get($url)->assertRedirect(route('login'));
        $response = $this->actingAs($user)->withSession(['portal_session_version' => 1])->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $response->assertStreamedContent("%PDF-1.4\n%%EOF");
        $this->actingAs(User::factory()->create())->withSession(['portal_session_version' => 1])->get($url)->assertNotFound();
        foreach (['pending', 'failed', 'rejected'] as $status) {
            $file->update(['scan_status' => $status]);
            $this->actingAs($user)->withSession(['portal_session_version' => 1])->get($url)->assertNotFound();
        }
        $file->update(['scan_status' => 'clean']);
        Storage::disk('private')->put($file->path, '<html><script>alert(1)</script></html>');
        $this->get($url)->assertStatus(415);
        Storage::disk('private')->delete($file->path);
        $this->get($url)->assertNotFound();
    }

    public function test_admin_can_preview_final_file_with_mfa_and_action_is_audited(): void
    {
        Storage::fake('private');
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $version = app(SubmissionService::class)->draft($submission, $user);
        $version->update(['status' => 'final']);
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id]);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        $attachment = $version->attachments()->create(['uploaded_file_id' => $file->id, 'purpose' => 'portfolio_main']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->withSession(['portal_session_version' => 1])->get(route('files.preview', $attachment))->assertNotFound();
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $this->get(route('files.preview', $attachment))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'file.previewed']);
    }

    public function test_admin_page_renders_sections_without_exposing_drafts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        Livewire::actingAs($admin)->test(Recruitment::class)->assertSee('Ringkasan rekrutmen')->call('navigate', 'positions')->assertSee('Tambah data')->call('navigate', 'settings')->assertSee('Pengaturan portal')->call('navigate', 'operations')->assertSee('Status pengiriman email');
    }
}
