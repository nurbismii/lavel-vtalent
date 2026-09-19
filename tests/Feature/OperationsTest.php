<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendSubmissionEmail;
use App\Models\AppSetting;
use App\Models\EmailDelivery;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_and_revision_keep_receipt_without_queuing_email(): void
    {
        Queue::fake();
        Mail::fake();
        $s = Submission::factory()->create();
        $u = $s->application->user;
        $service = app(SubmissionService::class);
        $v = $service->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id]);
        $final = $service->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $this->assertSame('final', $final->status);
        $this->assertNotEmpty($final->receipt);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service->revise($s->fresh(), $admin, 'Perbaiki dokumen pengumpulan', now()->addWeek()->toDateTimeString());
        $this->assertSame('revision', $s->fresh()->status->value);
        $this->assertSame($final->receipt, $v->fresh()->receipt);
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNotPushed(SendSubmissionEmail::class);
        Mail::assertNothingSent();
    }

    public function test_legacy_jobs_are_cancelled_without_sending_or_changing_sent_history(): void
    {
        Mail::fake();
        foreach (['finalized', 'revision'] as $event) {
            $delivery = EmailDelivery::factory()->create(['event' => $event]);
            $job = new SendSubmissionEmail($delivery->id);
            $job->handle();
            $this->assertSame('cancelled', $delivery->fresh()->status);
            $delivery->update(['status' => 'sent']);
            $job->handle();
            $job->failed();
            $this->assertSame('sent', $delivery->fresh()->status);
        }
        Mail::assertNothingSent();
    }

    public function test_cleanup_removes_only_old_unattached_uploads(): void
    {
        Storage::fake('private');
        $orphan = UploadedFile::factory()->create(['created_at' => now()->subDays(2)]);
        Storage::disk('private')->put($orphan->path, 'test');
        $recent = UploadedFile::factory()->create();
        Storage::disk('private')->put($recent->path, 'test');
        $this->artisan('portal:cleanup')->assertSuccessful();
        $this->assertModelMissing($orphan);
        $this->assertModelExists($recent);
        Storage::disk('private')->assertMissing($orphan->path);
    }

    public function test_retention_is_disabled_until_explicitly_enabled(): void
    {
        Storage::fake('private');
        $s = Submission::factory()->create();
        $s->application->update(['archived_at' => now()->subYears(2), 'active_user_id' => null]);
        $this->artisan('portal:cleanup')->assertSuccessful();
        $this->assertNull($s->application->fresh()->purged_at);
        AppSetting::create(['key' => 'retention_enabled', 'value' => true]);
        $this->artisan('portal:cleanup')->assertSuccessful();
        $this->assertNotNull($s->application->fresh()->purged_at);
    }
}
