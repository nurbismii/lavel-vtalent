<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\EmployeeEmails;
use App\Jobs\SendBroadcastEmail;
use App\Jobs\SendCandidateAccess;
use App\Models\EmailBroadcast;
use App\Models\EmailBroadcastDelivery;
use App\Models\User;
use App\Services\EmailBroadcastService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmailBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    private function message(): array
    {
        return ['recipients' => 'employee@example.test', 'subject' => 'Informasi kandidat', 'body' => '<p>Mohon memperbarui data Anda.</p>'];
    }

    public function test_excel_emails_are_deduplicated_and_external_recipients_are_queued_after_preview(): void
    {
        Queue::fake([SendBroadcastEmail::class]);
        $admin = $this->admin();
        $message = [...$this->message(), 'recipients' => "EMPLOYEE@example.test\r\nsecond@example.test\temployee@example.test;second@example.test, employee@example.test"];
        $page = Livewire::actingAs($admin)->test(EmployeeEmails::class)->set('data', $message);

        $page->call('previewEmail')->assertHasNoErrors()
            ->assertSet('preview.emails', ['employee@example.test', 'second@example.test'])
            ->assertSee('Pratinjau email')->call('send')->assertHasNoErrors()
            ->assertSet('preview', null)->assertSee('2 email disiapkan untuk dikirim');

        $broadcast = EmailBroadcast::sole();
        $this->assertSame($admin->id, $broadcast->created_by);
        $this->assertSame('Informasi kandidat', $broadcast->subject);
        $this->assertSame(['employee@example.test', 'second@example.test'], $broadcast->deliveries()->orderBy('id')->pluck('email')->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_email.queued', 'target_id' => $broadcast->id]);
        Queue::assertPushed(SendBroadcastEmail::class, 2);
    }

    public function test_repeated_submission_key_does_not_queue_the_same_blast_twice(): void
    {
        Queue::fake([SendBroadcastEmail::class]);
        $admin = $this->admin();
        $service = app(EmailBroadcastService::class);
        $content = $service->prepare($this->message());
        $key = (string) Str::uuid();
        $first = $service->queue($admin, $content, $key);

        $second = $service->queue($admin, $content, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('email_broadcasts', 1);
        $this->assertDatabaseCount('email_broadcast_deliveries', 1);
        Queue::assertPushed(SendBroadcastEmail::class, 1);
    }

    #[TestWith(['recipients', 'wrong-email', 'data.recipients'])]
    #[TestWith(['recipients', '', 'data.recipients'])]
    #[TestWith(['subject', '', 'data.subject'])]
    #[TestWith(['subject', "Notice\r\nBcc: other@example.test", 'data.subject'])]
    #[TestWith(['body', '<p><br></p><p>&nbsp;</p>', 'data.body'])]
    #[TestWith(['body', '<script>alert(1)</script>', 'data.body'])]
    public function test_invalid_inputs_never_create_or_queue_a_blast(string $field, string $value, string $error): void
    {
        Queue::fake();
        $message = [...$this->message(), $field => $value];

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $message)
            ->call('previewEmail')->assertHasErrors($error)->assertSet('preview', null);

        $this->assertDatabaseCount('email_broadcasts', 0);
        $this->assertDatabaseCount('email_broadcast_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_recipient_limit_accepts_100_and_rejects_101_unique_addresses(): void
    {
        Queue::fake();
        $recipients = implode("\n", array_map(fn (int $number): string => "employee$number@example.test", range(1, 100)));
        $page = Livewire::actingAs($this->admin())->test(EmployeeEmails::class)
            ->set('data', [...$this->message(), 'recipients' => $recipients])->call('previewEmail')->assertHasNoErrors();

        $page->set('data.recipients', $recipients."\nextra@example.test")->call('previewEmail')
            ->assertHasErrors('data.recipients')->assertSee('Paste 1–100 email valid');

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_editing_the_message_requires_a_new_preview_before_sending(): void
    {
        Queue::fake();
        $page = Livewire::actingAs($this->admin())->test(EmployeeEmails::class)
            ->set('data', $this->message())->call('previewEmail')->assertHasNoErrors();

        $page->set('data.subject', 'Subjek berubah')->assertSet('preview', null)
            ->call('send')->assertHasErrors('delivery')->assertSee('Periksa pratinjau pesan');

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_candidate_cannot_open_the_page_and_revoked_admin_cannot_send(): void
    {
        Queue::fake();
        Livewire::actingAs(User::factory()->create())->test(EmployeeEmails::class)->assertForbidden();
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test(EmployeeEmails::class)
            ->set('data', $this->message())->call('previewEmail');
        $admin->update(['active' => false]);

        $page->call('send')->assertForbidden();

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_admin_without_authenticator_cannot_access_blast_email(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))
            ->test(EmployeeEmails::class)->assertForbidden();
    }

    public function test_mailer_log_is_rejected_without_creating_a_blast(): void
    {
        Queue::fake();
        config(['mail.default' => 'log']);

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->call('previewEmail')->call('send')->assertHasErrors('message');

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_synchronous_queue_is_rejected_in_production(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'sync']);
        app()->instance('env', 'production');

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->call('previewEmail')->call('send')->assertHasErrors('delivery');

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_email_uses_the_shared_template_sanitizes_content_and_sends_each_recipient_privately_once(): void
    {
        $this->freezeTime();
        Queue::fake([SendBroadcastEmail::class]);
        $message = [...$this->message(),
            'recipients' => "first@example.test\nsecond@example.test",
            'subject' => 'Informasi <penting>',
            'body' => '<p><strong>Pengumuman</strong></p><ul><li>Data terbaru</li></ul><p><a href="https://example.test/hr">Buka panduan</a></p><script>secret()</script><a href="javascript:alert(1)">Bahaya</a>',
        ];
        $service = app(EmailBroadcastService::class);
        $broadcast = $service->queue($this->admin(), $service->prepare($message), (string) Str::uuid());
        $deliveries = $broadcast->deliveries()->get();

        foreach ($deliveries as $delivery) {
            (new SendBroadcastEmail($delivery->id))->handle();
        }
        (new SendBroadcastEmail($deliveries->first()->id))->handle();

        $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
        $this->assertCount(2, $messages);
        $this->assertSame(['first@example.test', 'second@example.test'], $messages->map(fn ($mail) => $mail->getTo()[0]->getAddress())->all());
        foreach ($messages as $mail) {
            $this->assertCount(1, $mail->getTo());
            $this->assertSame([], $mail->getCc());
            $this->assertSame([], $mail->getBcc());
            $this->assertSame('Informasi <penting>', $mail->getSubject());
            $this->assertStringContainsString('Informasi &lt;penting&gt;', $mail->getHtmlBody());
            $this->assertStringContainsString('<strong>Pengumuman</strong>', $mail->getHtmlBody());
            $this->assertStringContainsString('<li>Data terbaru</li>', $mail->getHtmlBody());
            $this->assertStringContainsString('href="https://example.test/hr"', $mail->getHtmlBody());
            $this->assertStringContainsString('#415e9e', $mail->getHtmlBody());
            $this->assertStringContainsString('cid:', $mail->getHtmlBody());
            $this->assertStringContainsString('Pengumuman', $mail->getTextBody());
            $this->assertStringNotContainsString('<script', $mail->getHtmlBody());
            $this->assertStringNotContainsString('javascript:', $mail->getHtmlBody());
            $this->assertStringNotContainsString('Tombol tidak berfungsi?', $mail->getHtmlBody());
        }
        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $deliveries->first()->id, 'status' => 'sent', 'attempts' => 1, 'last_error' => null]);
        Queue::assertPushed(SendBroadcastEmail::class, 2);
    }

    public function test_provider_rate_limit_records_safe_reason_then_marks_failed_after_exhausted_retries(): void
    {
        Log::spy();
        $delivery = EmailBroadcastDelivery::factory()->create();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('429 Too many emails. SMTP password: sensitive-value', 429));
        $job = new SendBroadcastEmail($delivery->id);

        try {
            $job->handle();
            $this->fail('Expected delivery error.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('membatasi laju', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-value', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $delivery->id, 'status' => 'retrying', 'attempts' => 1]);
        $this->assertStringContainsString('membatasi laju', $delivery->fresh()->last_error);
        Log::shouldHaveReceived('warning')->once()->with('Pengiriman blast email kandidat tertunda.', [
            'delivery_id' => $delivery->id, 'category' => 'provider_rate_limit', 'exception_type' => \RuntimeException::class, 'smtp_code' => 429,
        ]);

        $job->failed();
        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $delivery->id, 'status' => 'failed']);
    }

    public function test_failed_email_can_be_retried_without_resending_other_recipients(): void
    {
        Queue::fake([SendBroadcastEmail::class]);
        $failed = EmailBroadcastDelivery::factory()->create(['status' => 'failed', 'attempts' => 3, 'last_error' => 'Koneksi terputus']);
        $sent = EmailBroadcastDelivery::factory()->create(['email_broadcast_id' => $failed->email_broadcast_id, 'status' => 'sent']);
        $page = Livewire::actingAs($this->admin())->test(EmployeeEmails::class);

        $page->call('retryDelivery', $failed->id)->assertHasNoErrors()
            ->call('retryDelivery', $failed->id)->assertHasErrors('delivery')
            ->call('retryDelivery', $sent->id)->assertHasErrors('delivery');

        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $failed->id, 'status' => 'pending', 'attempts' => 3, 'last_error' => null]);
        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $sent->id, 'status' => 'sent']);
        Queue::assertPushed(SendBroadcastEmail::class, fn (SendBroadcastEmail $job): bool => $job->deliveryId === $failed->id);
        Queue::assertPushed(SendBroadcastEmail::class, 1);
    }

    public function test_history_status_filter_is_scoped_to_the_selected_blast(): void
    {
        $first = EmailBroadcastDelivery::factory()->create(['status' => 'failed']);
        EmailBroadcastDelivery::factory()->create(['email_broadcast_id' => $first->email_broadcast_id, 'status' => 'sent']);
        EmailBroadcastDelivery::factory()->create(['status' => 'failed']);

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)
            ->call('selectBroadcast', $first->email_broadcast_id)->set('statusFilter', 'failed')
            ->assertViewHas('deliveries', fn ($rows): bool => $rows->getCollection()->modelKeys() === [$first->id])
            ->set('statusFilter', "failed' OR 1=1 --")
            ->assertViewHas('deliveries', fn ($rows): bool => $rows->total() === 0);
    }

    public function test_blast_and_access_emails_share_the_existing_rate_limit(): void
    {
        config(['mail.queue_interval_seconds' => 11]);
        $access = new SendCandidateAccess(1);
        $blast = new SendBroadcastEmail(1);
        $underlying = Mockery::mock(Job::class);
        $underlying->shouldReceive('release')->once()->with(Mockery::on(fn ($delay): bool => $delay >= 11));
        $blast->setJob($underlying);
        $sent = 0;
        $run = function ($job) use (&$sent): void {
            app(Pipeline::class)->send($job)->through($job->middleware())->then(function () use (&$sent): void {
                $sent++;
            });
        };

        $run($access);
        $run($blast);

        $this->assertSame(1, $sent);
        $this->travel(15)->seconds();
        $run($blast);
        $this->assertSame(2, $sent);
    }
}
