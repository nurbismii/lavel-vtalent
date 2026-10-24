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
use Illuminate\Http\UploadedFile;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        Storage::fake('private');
        $admin = $this->admin();
        $service = app(EmailBroadcastService::class);
        $content = $service->prepare([...$this->message(), 'attachment' => [
            UploadedFile::fake()->create('panduan.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf'),
        ]]);
        $key = (string) Str::uuid();
        $first = $service->queue($admin, $content, $key);

        $second = $service->queue($admin, $content, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('email_broadcasts', 1);
        $this->assertDatabaseCount('email_broadcast_deliveries', 1);
        Storage::disk('private')->assertExists(array_column($first->attachmentFiles(), 'path'));
        $this->assertCount(2, Storage::disk('private')->allFiles('email-broadcasts'));
        Queue::assertPushed(SendBroadcastEmail::class, 1);
    }

    public function test_all_attachments_are_previewed_stored_privately_and_sent_to_every_recipient_even_on_retry(): void
    {
        Storage::fake('private');
        Queue::fake([SendBroadcastEmail::class]);
        $contents = "%PDF-1.7\nPanduan wawancara kandidat";
        $schedule = "%PDF-1.7\nJadwal wawancara kandidat";
        $uploads = [
            UploadedFile::fake()->createWithContent('panduan wawancara.pdf', $contents)->size(6144),
            UploadedFile::fake()->createWithContent('jadwal.pdf', $schedule)->size(4096),
        ];
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test(EmployeeEmails::class)
            ->set('data', [...$this->message(), 'recipients' => "first@example.test\nsecond@example.test"])
            ->set('data.attachment', $uploads)->call('previewEmail')->assertHasNoErrors()
            ->assertSee('Lampiran:')->assertSee('panduan wawancara.pdf')->assertSee('jadwal.pdf');
        $this->assertSame([], Storage::disk('private')->allFiles('email-broadcasts'));

        $page->call('send')->assertHasNoErrors()->assertSet('preview', null);

        $broadcast = EmailBroadcast::sole();
        $attachments = $broadcast->attachmentFiles();
        $this->assertSame(['panduan wawancara.pdf', 'jadwal.pdf'], array_column($attachments, 'name'));
        $this->assertSame(['application/pdf', 'application/pdf'], array_column($attachments, 'mime'));
        Storage::disk('private')->assertExists(array_column($attachments, 'path'));
        $this->assertSame($contents, Storage::disk('private')->get($attachments[0]['path']));
        $this->assertSame($schedule, Storage::disk('private')->get($attachments[1]['path']));
        Storage::disk('private')->deleteDirectory('livewire-tmp');
        $deliveries = $broadcast->deliveries()->orderBy('id')->get();
        (new SendBroadcastEmail($deliveries[0]->id))->handle();
        $deliveries[1]->update(['status' => 'failed']);
        app(EmailBroadcastService::class)->retry($admin, $deliveries[1]->id);
        (new SendBroadcastEmail($deliveries[1]->id))->handle();

        $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
        $this->assertCount(2, $messages);
        foreach ($messages as $mail) {
            $attachments = array_values(array_filter($mail->getAttachments(), fn ($part): bool => $part->getDisposition() === 'attachment'));
            $this->assertCount(2, $attachments);
            $this->assertSame('panduan wawancara.pdf', $attachments[0]->getFilename());
            $this->assertSame('application', $attachments[0]->getMediaType());
            $this->assertSame('pdf', $attachments[0]->getMediaSubtype());
            $this->assertSame($contents, $attachments[0]->getBody());
            $this->assertSame('jadwal.pdf', $attachments[1]->getFilename());
            $this->assertSame('application', $attachments[1]->getMediaType());
            $this->assertSame('pdf', $attachments[1]->getMediaSubtype());
            $this->assertSame($schedule, $attachments[1]->getBody());
        }
        $this->assertSame(2, $broadcast->deliveries()->where('status', 'sent')->count());
        Queue::assertPushed(SendBroadcastEmail::class, 3);
    }

    #[TestWith(['panduan.pdf', 10241, 'application/pdf', 'data.attachment'])]
    #[TestWith(['script.exe', 10, 'application/pdf', 'data.attachment.0'])]
    public function test_invalid_attachment_never_creates_or_queues_a_blast(string $name, int $kilobytes, string $mime, string $error): void
    {
        Storage::fake('private');
        Queue::fake();
        $upload = UploadedFile::fake()->create($name, $kilobytes, $mime);

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->set('data.attachment', [$upload])->call('previewEmail')
            ->assertHasErrors($error)->assertSet('preview', null);

        $this->assertDatabaseCount('email_broadcasts', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('email-broadcasts'));
        Queue::assertNothingPushed();
    }

    public function test_attachment_with_spoofed_pdf_extension_is_rejected_before_queueing(): void
    {
        Storage::fake('private');
        Queue::fake();
        $source = UploadedFile::fake()->createWithContent('panduan.pdf', '<html><script>alert(1)</script></html>');
        $upload = new UploadedFile($source->getRealPath(), 'panduan.pdf', 'application/pdf', null, true);

        try {
            app(EmailBroadcastService::class)->queue($this->admin(), [
                'emails' => ['employee@example.test'], 'subject' => 'Informasi kandidat',
                'body' => '<p>Informasi</p>', 'attachment' => [$upload],
            ], (string) Str::uuid());
            $this->fail('Expected an invalid attachment to stop queueing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.attachment.0', $exception->errors());
        }

        $this->assertDatabaseCount('email_broadcasts', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('email-broadcasts'));
        Queue::assertNothingPushed();
    }

    public function test_more_than_ten_attachments_are_rejected(): void
    {
        Storage::fake('private');
        Queue::fake();

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->set('data.attachment', array_map(fn (int $number): UploadedFile => UploadedFile::fake()->create("lampiran-$number.pdf", 10, 'application/pdf'), range(1, 11)))
            ->call('previewEmail')->assertHasErrors('data.attachment')->assertSet('preview', null);

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_ten_attachments_can_be_previewed(): void
    {
        Storage::fake('private');
        Queue::fake();

        $page = Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->set('data.attachment', array_map(fn (int $number): UploadedFile => UploadedFile::fake()->create("lampiran-$number.pdf", 10, 'application/pdf'), range(1, 10)))
            ->call('previewEmail')->assertHasNoErrors();

        $this->assertCount(10, $page->get('preview')['attachment']);
        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_attachments_with_combined_size_over_ten_megabytes_are_rejected(): void
    {
        Storage::fake('private');
        Queue::fake();

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->set('data.attachment', [
                UploadedFile::fake()->create('panduan.pdf', 6144, 'application/pdf'),
                UploadedFile::fake()->create('jadwal.pdf', 4097, 'application/pdf'),
            ])->call('previewEmail')->assertHasErrors('data.attachment')->assertSet('preview', null)
            ->assertSee('Total ukuran lampiran maksimal 10 MB.');

        $this->assertDatabaseCount('email_broadcasts', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('email-broadcasts'));
        Queue::assertNothingPushed();
    }

    public function test_existing_single_attachment_still_appears_in_history_and_is_sent(): void
    {
        $contents = "%PDF-1.7\nPanduan sebelumnya";
        Storage::fake('private')->put('email-broadcasts/previous.pdf', $contents);
        $broadcast = EmailBroadcast::factory()->create(['attachment' => [
            'path' => 'email-broadcasts/previous.pdf', 'name' => 'panduan sebelumnya.pdf', 'mime' => 'application/pdf',
        ]]);
        $delivery = EmailBroadcastDelivery::factory()->create(['email_broadcast_id' => $broadcast->id]);

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)
            ->call('selectBroadcast', $broadcast->id)->assertSee('panduan sebelumnya.pdf');
        (new SendBroadcastEmail($delivery->id))->handle();

        $mail = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $attachments = array_values(array_filter($mail->getAttachments(), fn ($part): bool => $part->getDisposition() === 'attachment'));
        $this->assertCount(1, $attachments);
        $this->assertSame('panduan sebelumnya.pdf', $attachments[0]->getFilename());
        $this->assertSame($contents, $attachments[0]->getBody());
        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $delivery->id, 'status' => 'sent']);
    }

    public function test_existing_private_file_path_cannot_be_used_as_an_attachment(): void
    {
        Storage::fake('private')->put('candidate-forms/private.pdf', 'Confidential candidate information');
        Queue::fake();

        Livewire::actingAs($this->admin())->test(EmployeeEmails::class)->set('data', $this->message())
            ->set('data.attachment', ['candidate-forms/private.pdf'])->call('previewEmail')
            ->assertHasErrors('data.attachment')->assertSet('preview', null);

        $this->assertDatabaseCount('email_broadcasts', 0);
        Queue::assertNothingPushed();
    }

    public function test_all_attachments_are_removed_when_creating_the_blast_fails(): void
    {
        Storage::fake('private');
        Queue::fake();
        $admin = $this->admin();
        $service = app(EmailBroadcastService::class);
        $content = $service->prepare([...$this->message(), 'attachment' => [
            UploadedFile::fake()->create('panduan.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf'),
        ]]);
        EmailBroadcast::creating(function (): void {
            throw new \RuntimeException('Broadcast could not be saved.');
        });

        try {
            $service->queue($admin, $content, (string) Str::uuid());
            $this->fail('Expected broadcast persistence to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Broadcast could not be saved.', $exception->getMessage());
        }

        $this->assertDatabaseCount('email_broadcasts', 0);
        $this->assertDatabaseCount('email_broadcast_deliveries', 0);
        $this->assertSame([], Storage::disk('private')->allFiles('email-broadcasts'));
        Queue::assertNothingPushed();
    }

    public function test_missing_attachment_keeps_delivery_retrying_without_sending_an_incomplete_email(): void
    {
        Storage::fake('private')->put('email-broadcasts/available.pdf', '%PDF-1.7');
        $broadcast = EmailBroadcast::factory()->create(['attachment' => [
            ['path' => 'email-broadcasts/available.pdf', 'name' => 'panduan.pdf', 'mime' => 'application/pdf'],
            ['path' => 'email-broadcasts/missing.pdf', 'name' => 'jadwal.pdf', 'mime' => 'application/pdf'],
        ]]);
        $delivery = EmailBroadcastDelivery::factory()->create(['email_broadcast_id' => $broadcast->id]);

        try {
            (new SendBroadcastEmail($delivery->id))->handle();
            $this->fail('Expected missing attachment to stop delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Sistem akan mencoba ulang', $exception->getMessage());
        }

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertDatabaseHas('email_broadcast_deliveries', ['id' => $delivery->id, 'status' => 'retrying', 'attempts' => 1]);
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
