<?php

namespace Tests\Feature;

use App\Jobs\SendSubmissionEmail;
use App\Models\AppSetting;
use App\Models\EmailDelivery;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_failure_does_not_undo_final(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Queue unavailable'));
        $s = Submission::factory()->create();
        $u = $s->application->user;
        $v = app(SubmissionService::class)->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id]);
        $final = app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $this->assertSame('final', $final->status);
        $this->assertSame('failed', EmailDelivery::first()->status);
    }

    public function test_mail_failure_tracks_retry_without_altering_receipt(): void
    {
        Queue::fake();
        $s = Submission::factory()->create();
        $u = $s->application->user;
        $v = app(SubmissionService::class)->draft($s, $u);
        $f = UploadedFile::factory()->create(['submission_id' => $s->id, 'recruitment_application_id' => $s->recruitment_application_id, 'uploader_id' => $u->id]);
        $final = app(SubmissionService::class)->save($s, $u, $v->id, ['attachments' => [['id' => $f->id]]], true);
        $delivery = EmailDelivery::first();
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Mail unavailable'));
        try {
            (new SendSubmissionEmail($delivery->id))->handle();
            $this->fail('Mail should fail');
        } catch (\RuntimeException) {
            $this->assertSame('retrying', $delivery->fresh()->status);
            $this->assertSame($final->receipt, $v->fresh()->receipt);
        }
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
