<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\SubmissionStatus;
use App\Models\EmailDelivery;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\RecruitmentService;
use App\Services\SubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $draft = app(SubmissionService::class)->draft($submission, $user);
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id]);

        return [$submission, $user, $draft, $file];
    }

    private function data(UploadedFile $file): array
    {
        return ['notes' => 'Catatan', 'links' => [], 'attachments' => [['id' => $file->id, 'description' => '']]];
    }

    public function test_finalization_is_idempotent_and_other_submission_remains_unchanged(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $test = Submission::factory()->create(['recruitment_application_id' => $submission->recruitment_application_id, 'type' => 'technical_test']);
        $service = app(SubmissionService::class);
        $one = $service->save($submission, $user, $draft->id, $this->data($file), true);
        $two = $service->save($submission, $user, $draft->id, $this->data($file), true);
        $this->assertSame($one->receipt, $two->receipt);
        $this->assertSame(0, EmailDelivery::count());
        $this->assertSame(SubmissionStatus::NotStarted, $test->fresh()->status);
        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
    }

    public function test_final_requires_main_document(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->save($submission, $user, $draft->id, ['notes' => '', 'links' => [], 'attachments' => []], true);
    }

    public function test_pending_scan_blocks_finalization(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $file->update(['scan_status' => ScanStatus::Pending]);
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->save($submission, $user, $draft->id, $this->data($file), true);
    }

    public function test_evidence_requires_description_but_not_when_absent(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $evidence = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id, 'purpose' => 'portfolio_evidence']);
        $data = $this->data($file);
        $data['attachments'][] = ['id' => $evidence->id, 'description' => ''];
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->save($submission, $user, $draft->id, $data, true);
    }

    public function test_revision_preserves_final_and_file_references(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $service = app(SubmissionService::class);
        $final = $service->save($submission, $user, $draft->id, $this->data($file), true);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service->revise($submission, $admin, 'Perbarui halaman terakhir', now()->addWeek()->format('Y-m-d H:i'));
        $this->assertSame('final', $final->fresh()->status);
        $this->assertSame($final->id, $submission->fresh()->current_version_id);
        $revision = $submission->versions()->where('status', 'draft')->firstOrFail();
        $this->assertSame(2, $revision->number);
        $this->assertSame($file->id, $revision->attachments->first()->uploaded_file_id);
        $service->save($submission, $user, $revision->id, $this->data($file), true);
        $this->assertSame(2, $submission->versions()->where('status', 'final')->count());
        $this->assertSame(1, UploadedFile::where('submission_id', $submission->id)->count());
    }

    public function test_after_deadline_draft_is_read_only(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $submission->update(['deadline' => now()->subSecond()]);
        $this->expectException(AuthorizationException::class);
        app(SubmissionService::class)->save($submission, $user, $draft->id, $this->data($file));
    }

    public function test_replacement_pending_preserves_old_draft_main(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $service = app(SubmissionService::class);
        $service->save($submission, $user, $draft->id, $this->data($file));
        $replacement = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $user->id, 'scan_status' => 'pending']);
        try {
            $service->save($submission, $user, $draft->id, $this->data($replacement));
            $this->fail('Pending replacement must be rejected');
        } catch (ValidationException) {
            $this->assertSame($file->id, $draft->fresh()->attachments->first()->uploaded_file_id);
        }
    }

    public function test_second_active_application_is_rejected_and_old_account_reused(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $position = Position::factory()->create();
        $period = RecruitmentPeriod::factory()->create();
        $data = ['name' => 'Kandidat', 'email' => 'person@example.com', 'position_id' => $position->id, 'recruitment_period_id' => $period->id, 'portfolio_deadline' => now()->addWeek()->format('Y-m-d H:i'), 'test_deadline' => now()->addWeek()->format('Y-m-d H:i'), 'task_label' => 'Tes'];
        $service = app(RecruitmentService::class);
        $first = $service->create($admin, $data);
        $this->assertNotNull($first['password']);
        try {
            $service->create($admin, $data);
            $this->fail('Duplicate active application');
        } catch (ValidationException) {
            $this->assertSame(1, RecruitmentApplication::count());
        }
        $service->archive($admin, $first['application'], 'Periode sebelumnya selesai');
        $second = $service->create($admin, $data);
        $this->assertNull($second['password']);
        $this->assertSame($first['application']->user_id, $second['application']->user_id);
        $this->assertSame(2, RecruitmentApplication::count());
    }

    public function test_exempt_is_not_final(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $admin = User::factory()->create(['role' => Role::Admin]);
        app(SubmissionService::class)->exempt($submission, $admin, 'Portofolio tidak diperlukan');
        $this->assertSame(SubmissionStatus::Exempt, $submission->fresh()->status);
        $this->assertNull($submission->fresh()->current_version_id);
        $this->assertSame(0, EmailDelivery::count());
    }

    public function test_unsafe_link_scheme_is_rejected(): void
    {
        [$submission,$user,$draft,$file] = $this->fixture();
        $data = $this->data($file);
        $data['links'] = [['label' => 'Link', 'url' => 'javascript:alert(1)']];
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->save($submission,$user,$draft->id,$data);
    }
}
