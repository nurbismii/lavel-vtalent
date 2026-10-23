<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Jobs\SendCandidateAccess;
use App\Models\AccessDelivery;
use App\Models\RecruitmentApplication;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\RecruitmentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecruitmentToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasted_recipient_emails_preserve_selection_and_respect_filters_and_portfolio_uploads(): void
    {
        $first = RecruitmentApplication::factory()->create();
        $second = RecruitmentApplication::factory()->create($first->only('position_id', 'recruitment_period_id'));
        $other = RecruitmentApplication::factory()->create();
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->set('recipientPositionFilter', (string) $first->position_id)
            ->set('recipientPeriodFilter', (string) $first->recruitment_period_id)
            ->set('recipientIds', [(string) $first->user_id])->set('confirmAccess', true)
            ->call('selectRecipientsByEmail', strtoupper($second->user->email)."\r\n".$first->user->email.';'.$second->user->email)
            ->assertHasNoErrors()->assertSet('recipientIds', [(string) $first->user_id, (string) $second->user_id])
            ->assertSet('recipientSearch', '')->assertSet('confirmAccess', false);
        $page->call('selectRecipientsByEmail', $other->user->email)->assertHasErrors('recipientSearch')
            ->assertSet('recipientIds', [(string) $first->user_id, (string) $second->user_id]);
        $submission = Submission::factory()->create(['recruitment_application_id' => $second->id]);
        UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $second->id, 'uploader_id' => $second->user_id]);
        $page->call('selectRecipientsByEmail', $second->user->email)->assertHasErrors('recipientSearch');
        $this->assertDatabaseCount('access_deliveries', 0);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    #[TestWith(['sent'])]
    #[TestWith(['retrying'])]
    #[TestWith(['pending'])]
    #[TestWith(['processing'])]
    #[TestWith(['failed'])]
    #[TestWith(['cancelled'])]
    public function test_access_email_status_filter_only_shows_matching_deliveries(string $status): void
    {
        $user = User::factory()->create();
        $deliveries = collect(['sent', 'retrying', 'pending', 'processing', 'failed', 'cancelled'])
            ->mapWithKeys(fn (string $deliveryStatus): array => [$deliveryStatus => AccessDelivery::create([
                'user_id' => $user->id, 'email' => $user->email, 'password' => null,
                'message' => 'Pesan HR', 'status' => $deliveryStatus, 'expires_at' => now()->addDay(),
            ])]);

        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->assertViewHas('accessDeliveries', fn ($deliveries): bool => $deliveries->total() === 6)
            ->set('recipientIds', [$user->id])->set('confirmAccess', true)
            ->set('accessStatusFilter', $status)
            ->assertViewHas('accessDeliveries', fn ($matches): bool => $matches->getCollection()->modelKeys() === [$deliveries[$status]->id])
            ->assertSet('recipientIds', [$user->id])->assertSet('confirmAccess', true)
            ->set('accessStatusFilter', '')
            ->assertViewHas('accessDeliveries', fn ($deliveries): bool => $deliveries->total() === 6);
    }

    public function test_access_email_filter_resets_its_pagination_and_shows_empty_results(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $attempt) {
            AccessDelivery::create([
                'user_id' => $user->id, 'email' => $user->email, 'password' => null,
                'message' => 'Pesan HR', 'status' => 'pending', 'expires_at' => now()->addDay(),
            ]);
        }

        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->call('setPage', 2, 'accessPage')->assertSet('paginators.accessPage', 2)
            ->set('accessStatusFilter', 'sent')->assertSet('paginators.accessPage', 1)
            ->assertViewHas('accessDeliveries', fn ($deliveries): bool => $deliveries->total() === 0)
            ->assertSee('Tidak ada email dengan status ini')
            ->set('accessStatusFilter', 'pending')
            ->assertViewHas('accessDeliveries', fn ($deliveries): bool => $deliveries->total() === 16 && $deliveries->count() === 15)
            ->set('accessStatusFilter', "sent' OR 1=1 --")
            ->assertViewHas('accessDeliveries', fn ($deliveries): bool => $deliveries->total() === 0);
    }

    public function test_email_recipients_filter_by_position_batch_and_search_and_clear_selection(): void
    {
        $application = RecruitmentApplication::factory()->create();
        $otherBatch = RecruitmentApplication::factory()->create(['position_id' => $application->position_id]);
        $otherPosition = RecruitmentApplication::factory()->create(['recruitment_period_id' => $application->recruitment_period_id]);

        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->set('recipientIds', [$application->user_id])->set('confirmAccess', true)
            ->set('recipientPositionFilter', (string) $application->position_id)
            ->assertSet('recipientIds', [])->assertSet('confirmAccess', false)
            ->assertSee($application->user->email)->assertSee($otherBatch->user->email)->assertDontSee($otherPosition->user->email)
            ->set('recipientIds', [$otherBatch->user_id])
            ->set('recipientPeriodFilter', (string) $application->recruitment_period_id)
            ->assertSet('recipientIds', [])
            ->assertSee($application->user->email)->assertDontSee($otherBatch->user->email)
            ->set('recipientSearch', $otherPosition->user->email)->assertDontSee($otherPosition->user->email)
            ->assertSee('Tidak ada kandidat aktif yang cocok.');

        $page->set('recipientSearch', '')->set('recipientPositionFilter', '')
            ->assertSee($otherPosition->user->email)->assertDontSee($otherBatch->user->email);
    }

    public function test_portfolio_upload_hides_recipient_and_blocks_stale_selection_without_resetting_password(): void
    {
        Queue::fake();
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $password = $user->password;
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->assertSee($user->email)->set('recipientIds', [$user->id]);
        $file = UploadedFile::factory()->create([
            'submission_id' => $submission->id,
            'recruitment_application_id' => $submission->recruitment_application_id,
            'uploader_id' => $user->id,
        ]);

        $page->set('hrMessage', 'Informasi akses dari HR')->set('confirmAccess', true)
            ->assertDontSee($user->email)->call('sendCandidateAccess')->assertHasErrors('recipientIds');

        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('access_deliveries', 0);
        Queue::assertNothingPushed();

        $file->delete();
        $page->set('recipientSearch', $user->email)->assertSee($user->email);
    }

    public function test_archived_portfolio_and_technical_upload_do_not_hide_current_candidate(): void
    {
        $file = UploadedFile::factory()->create();
        $application = $file->submission->application;
        $application->update(['archived_at' => now(), 'active_user_id' => null]);
        $current = RecruitmentApplication::factory()->create(['user_id' => $application->user_id, 'active_user_id' => $application->user_id]);
        $technical = UploadedFile::factory()->create(['purpose' => 'technical_result']);

        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->assertSee($current->user->email)->assertSee($technical->submission->application->user->email);
    }

    public function test_email_sends_only_to_selected_candidates_matching_filters(): void
    {
        Queue::fake();
        $application = RecruitmentApplication::factory()->create();
        $other = RecruitmentApplication::factory()->create();

        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->set('recipientPositionFilter', (string) $application->position_id)
            ->set('recipientPeriodFilter', (string) $application->recruitment_period_id)
            ->set('hrMessage', 'Informasi akses dari HR')->set('confirmAccess', true)
            ->set('recipientIds', [$other->user_id])->call('sendCandidateAccess')->assertHasErrors('recipientIds');

        $this->assertDatabaseCount('access_deliveries', 0);

        $page->set('recipientIds', [$application->user_id])->call('sendCandidateAccess')
            ->assertHasNoErrors()->assertSet('recipientIds', [])->assertSee('1 email akses dibuat.');

        $this->assertSame($application->user_id, AccessDelivery::sole()->user_id);
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

    public function test_legacy_pdf_task_management_and_download_are_removed(): void
    {
        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->assertDontSee('Soal tes teknis')->assertDontSee('taskFile')->assertSee('Portofolio');
        $this->get('/portal/technical-tasks/1/download')->assertNotFound();
        $this->assertDatabaseCount('technical_tasks', 0);
    }

    public function test_bulk_deadlines_rejects_legacy_technical_submissions(): void
    {
        $submission = Submission::factory()->create(['type' => 'technical_test']);
        $before = $submission->deadline;
        Livewire::actingAs($this->admin())->test(Recruitment::class)->call('navigate', 'tools')
            ->set('bulk', [...$submission->application->only('position_id', 'recruitment_period_id'), 'type' => 'technical_test', 'deadline' => now()->addWeek()->toDateTimeString(), 'reason' => 'Tidak boleh memperpanjang tes upload'])
            ->call('bulkDeadlines')->assertHasErrors('type');
        $this->assertTrue($submission->fresh()->deadline->equalTo($before));
    }

    public function test_candidate_cannot_invoke_hr_actions(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(Recruitment::class)->assertForbidden();
        $this->expectException(HttpException::class);
        app(RecruitmentToolsService::class)->sendAccess($user, [$user->id], 'Pesan dari HR');
    }
}
