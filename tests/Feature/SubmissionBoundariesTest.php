<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Filament\Pages\Recruitment;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\SubmissionService;
use App\Services\UploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as HttpFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubmissionBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $s = Submission::factory()->create();
        $u = $s->application->user;
        $v = app(SubmissionService::class)->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id, 'purpose' => 'portfolio_main']);

        return [$s, $u, $v, $f];
    }

    public function test_two_main_documents_rejected(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        $g = $f->replicate();
        $g->path = 'other.pdf';
        $g->save();
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id], ['id' => $g->id]]], true);
    }

    public function test_legacy_technical_upload_is_rejected_without_creating_a_draft_or_file(): void
    {
        Storage::fake('private');
        $submission = Submission::factory()->create(['type' => 'technical_test']);
        $user = $submission->application->user;
        Submission::factory()->create(['recruitment_application_id' => $submission->recruitment_application_id, 'status' => SubmissionStatus::Submitted]);

        $this->actingAs($user)->withSession(['portal_session_version' => $user->session_version])
            ->get(route('candidate.submission', $submission))->assertNotFound();
        $this->assertFalse($submission->fresh()->editable());
        try {
            app(UploadService::class)->store($submission, $user, HttpFile::fake()->create('result.pdf', 10, 'application/pdf'), 'technical_result');
            $this->fail('Unggahan tes teknis lama harus ditolak.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('uploaded_files', 0);
            $this->assertSame([], Storage::disk('private')->allFiles());
        }
        $this->assertDatabaseCount('submission_versions', 0);
    }

    public function test_legacy_technical_final_remains_read_only_and_cannot_be_revised(): void
    {
        $submission = Submission::factory()->create(['type' => 'technical_test', 'status' => SubmissionStatus::Submitted]);
        $version = $submission->versions()->create(['number' => 1, 'status' => 'final', 'submitted_at' => now(), 'receipt' => 'LEGACY-RECEIPT', 'links' => []]);
        $submission->update(['current_version_id' => $version->id]);
        $user = $submission->application->user;
        $this->actingAs($user)->withSession(['portal_session_version' => $user->session_version])
            ->get(route('candidate.submission', $submission))->assertSee('LEGACY-RECEIPT')->assertDontSee('type="file"', false)->assertDontSee('Simpan draf');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $submission->recruitment_application_id)
            ->call('selectTab', 'Arsip Tes Upload')->assertSee('LEGACY-RECEIPT')->assertDontSee('Buka revisi')->assertDontSee('Perpanjang tenggat');
        try {
            app(SubmissionService::class)->revise($submission, $admin, 'Revisi arsip tidak diizinkan', now()->addDay()->toDateTimeString());
            $this->fail('Arsip tes teknis tidak boleh dibuka ulang.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('submission_versions', 1);
        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
    }

    public function test_exact_deadline_is_accepted_but_later_is_rejected(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        $this->travelTo($s->deadline);
        $final = app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $this->assertSame('final', $final->status);
    }

    public function test_final_cannot_be_saved_as_draft(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $this->expectException(AuthorizationException::class);
        app(SubmissionService::class)->save($s, $u, $v->id, ['notes' => 'tampered', 'attachments' => []]);
    }

    public function test_failed_and_rejected_files_block_finalization(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        foreach (['failed', 'rejected'] as $status) {
            $f->update(['scan_status' => $status]);
            try {
                app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
                $this->fail('Unsafe file accepted');
            } catch (ValidationException) {
                $this->assertSame('draft', $v->fresh()->status);
            }
        }
    }

    public function test_foreign_attachment_is_rejected_without_changes(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        $foreign = UploadedFile::factory()->create();
        $this->expectException(HttpException::class);
        app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $foreign->id]]]);
    }

    public function test_expired_revision_keeps_old_final(): void
    {
        [$s, $u, $v, $f] = $this->fixture();
        $service = app(SubmissionService::class);
        $service->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service->revise($s, $admin, 'Perbaiki dokumen utama', now()->addDay()->format('Y-m-d H:i'));
        $this->travel(2)->days();
        $this->assertFalse($s->fresh()->editable());
        $this->assertSame($v->id, $s->fresh()->current_version_id);
        $this->assertSame(SubmissionStatus::Revision, $s->fresh()->status);
    }
}
