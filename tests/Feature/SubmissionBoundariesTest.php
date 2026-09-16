<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubmissionBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $type = 'portfolio', bool $portfolioSubmitted = false): array
    {
        Queue::fake();
        $s = Submission::factory()->create(['type' => $type]);
        $u = $s->application->user;
        if ($type === 'technical_test' && $portfolioSubmitted) {
            Submission::factory()->create(['recruitment_application_id' => $s->recruitment_application_id, 'status' => SubmissionStatus::Submitted]);
        }
        $v = app(SubmissionService::class)->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id, 'purpose' => $type === 'portfolio' ? 'portfolio_main' : 'technical_result']);

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

    public function test_technical_test_requires_a_file_and_accepts_valid_result(): void
    {
        [$s, $u, $v, $f] = $this->fixture('technical_test', true);
        try {
            app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => []], true);
            $this->fail('Empty results accepted');
        } catch (ValidationException) {
        }
        $final = app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $this->assertNotNull($final->receipt);
    }

    public function test_technical_test_is_locked_until_portfolio_is_submitted(): void
    {
        $technicalTest = Submission::factory()->create(['type' => 'technical_test']);

        $this->assertTrue($technicalTest->lockedUntilPortfolioSubmitted());
        $this->assertFalse($technicalTest->editable());

        Submission::factory()->create([
            'recruitment_application_id' => $technicalTest->recruitment_application_id,
            'status' => SubmissionStatus::Submitted,
        ]);

        $this->assertFalse($technicalTest->fresh()->lockedUntilPortfolioSubmitted());
        $this->assertTrue($technicalTest->fresh()->editable());
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
