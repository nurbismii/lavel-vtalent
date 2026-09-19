<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Jobs\SendCandidateAccess;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\Submission;
use App\Models\TechnicalTask;
use App\Models\User;
use App\Services\RecruitmentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecruitmentToolsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    public function test_bulk_extension_targets_only_unfinished_applications_in_selected_batch(): void
    {
        $first = Submission::factory()->create();
        $app = $first->application;
        $secondApp = RecruitmentApplication::factory()->create($app->only('position_id', 'recruitment_period_id'));
        $second = Submission::factory()->create(['recruitment_application_id' => $secondApp->id]);
        $final = Submission::factory()->create(['status' => 'submitted']);
        $other = Submission::factory()->create();
        $old = $other->deadline->toDateTimeString();
        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->set('bulk', [...$app->only('position_id', 'recruitment_period_id'), 'type' => 'portfolio', 'deadline' => now()->addMonth()->toDateTimeString(), 'reason' => 'Perpanjangan bersama'])
            ->call('bulkDeadlines')->assertHasNoErrors()->assertSee('2 tenggat berhasil diperpanjang');
        $this->assertTrue($first->fresh()->deadline->gt(now()->addWeeks(2)));
        $this->assertEquals($first->fresh()->deadline, $second->fresh()->deadline);
        $this->assertSame($old, $other->fresh()->deadline->toDateTimeString());
        $this->assertSame('submitted', $final->fresh()->status->value);
    }

    public function test_bulk_extension_rolls_back_every_change_when_one_deadline_is_invalid(): void
    {
        $first = Submission::factory()->create(['deadline' => now()->addDay()]);
        $app = $first->application;
        $secondApp = RecruitmentApplication::factory()->create($app->only('position_id', 'recruitment_period_id'));
        Submission::factory()->create(['recruitment_application_id' => $secondApp->id, 'deadline' => now()->addMonths(2)]);
        $old = $first->deadline->toDateTimeString();
        try {
            app(RecruitmentToolsService::class)->deadlines($this->admin(), [...$app->only('position_id', 'recruitment_period_id'), 'type' => 'portfolio', 'deadline' => now()->addMonth()->toDateTimeString(), 'reason' => 'Perpanjangan bersama']);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame($old, $first->fresh()->deadline->toDateTimeString());
            $this->assertDatabaseMissing('audit_logs', ['action' => 'submission.deadline_changed']);
        }
    }

    public function test_access_email_encrypts_password_sends_hr_message_and_clears_secret(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        app(RecruitmentToolsService::class)->sendAccess($this->admin(), [$user->id], 'Pesan HR: selamat mengikuti tes.');
        $delivery = AccessDelivery::sole();
        $password = $delivery->password;
        $this->assertNotSame($password, DB::table('access_deliveries')->value('password'));
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertArrayNotHasKey('password', $delivery->toArray());
        $job = new SendCandidateAccess($delivery->id);
        $job->handle();
        $job->handle();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame($user->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Pesan HR: selamat mengikuti tes.', $email->getTextBody());
        $this->assertStringContainsString($password, $email->getTextBody());
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->password);
    }

    public function test_stale_access_email_is_cancelled_without_sending_old_credentials(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        app(RecruitmentToolsService::class)->sendAccess($this->admin(), [$user->id], 'Pesan dari HR');
        $delivery = AccessDelivery::sole();
        $user->update(['password' => 'different-password']);
        (new SendCandidateAccess($delivery->id))->handle();
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->password);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_failed_email_keeps_same_password_for_retry_and_redacts_transport_errors(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        app(RecruitmentToolsService::class)->sendAccess($this->admin(), [$user->id], 'Pesan dari HR');
        $delivery = AccessDelivery::sole();
        $password = $delivery->password;
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport payload: '.$password));
        $job = new SendCandidateAccess($delivery->id);
        try {
            $job->handle();
            $this->fail('Expected transport failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString($password, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('retrying', $delivery->fresh()->status);
        $this->assertSame($password, $delivery->fresh()->password);
        Mail::shouldReceive('send')->once()->andReturn(null);
        $job->handle();
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->password);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_duplicate_pending_email_does_not_reset_existing_access(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $admin = $this->admin();
        $service = app(RecruitmentToolsService::class);
        $service->sendAccess($admin, [$user->id], 'Pesan dari HR');
        $hash = $user->fresh()->password;
        try {
            $service->sendAccess($admin, [$user->id], 'Pesan dari HR berikutnya');
            $this->fail('Expected pending delivery rejection');
        } catch (ValidationException) {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertDatabaseCount('access_deliveries', 1);
        }
    }

    public function test_email_rejects_log_mailer_without_resetting_password(): void
    {
        config(['mail.default' => 'log']);
        $user = User::factory()->create();
        $hash = $user->password;
        try {
            app(RecruitmentToolsService::class)->sendAccess($this->admin(), [$user->id], 'Pesan dari HR');
            $this->fail('Expected invalid mailer');
        } catch (ValidationException) {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertDatabaseCount('access_deliveries', 0);
        }
    }

    public function test_admin_uploads_pdf_and_candidate_download_is_gated_by_start_position_and_batch(): void
    {
        Storage::fake('private');
        $app = RecruitmentApplication::factory()->create();
        $start = now()->addHour()->startOfMinute();
        $data = [...$app->only('position_id', 'recruitment_period_id'), 'starts_at' => $start->copy()->timezone(AppSetting::valueFor('timezone'))->format('Y-m-d H:i')];
        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')->set('task', $data)
            ->set('taskFile', UploadedFile::fake()->create('soal.pdf', 10, 'application/pdf'))
            ->call('saveTechnicalTask')->assertHasNoErrors()->assertSee('Soal dan jadwal tes teknis berhasil disimpan');
        $task = TechnicalTask::sole();
        Storage::disk('private')->assertExists($task->path);
        $this->actingAs($app->user)->withSession(['portal_session_version' => $app->user->session_version]);
        $url = route('candidate.task.download', $task);
        $this->get($url)->assertForbidden();
        $this->travelTo($start);
        $this->get($url)->assertOk()->assertDownload('soal-tes-teknis.pdf');
        $other = RecruitmentApplication::factory()->create(['position_id' => $app->position_id]);
        $this->actingAs($other->user)->withSession(['portal_session_version' => $other->user->session_version])->get($url)->assertForbidden();
        $other->update(['position_id' => Position::factory()->create()->id, 'recruitment_period_id' => $app->recruitment_period_id]);
        $this->get($url)->assertForbidden();
        $app->update(['archived_at' => now(), 'active_user_id' => null]);
        $this->actingAs($app->user)->withSession(['portal_session_version' => $app->user->session_version])->get($url)->assertForbidden();
    }

    public function test_invalid_file_and_schedule_leave_no_task_or_orphan_file(): void
    {
        Storage::fake('private');
        $submission = Submission::factory()->create(['type' => 'technical_test', 'deadline' => now()->addDay()]);
        $data = [...$submission->application->only('position_id', 'recruitment_period_id'), 'starts_at' => now()->addWeek()->toDateTimeString()];
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')->set('task', $data);
        $page->set('taskFile', UploadedFile::fake()->create('bad.txt', 10, 'text/plain'))->call('saveTechnicalTask')->assertHasErrors('file');
        $page->set('taskFile', UploadedFile::fake()->create('soal.pdf', 10, 'application/pdf'))->call('saveTechnicalTask')->assertHasErrors('task.starts_at');
        $this->assertDatabaseCount('technical_tasks', 0);
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_candidate_cannot_invoke_hr_actions(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(Recruitment::class)->assertForbidden();
        $this->expectException(HttpException::class);
        app(RecruitmentToolsService::class)->sendAccess($user, [$user->id], 'Pesan dari HR');
    }
}
