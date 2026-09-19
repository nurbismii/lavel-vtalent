<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendCandidateAccess;
use App\Models\AccessDelivery;
use App\Models\User;
use App\Services\RecruitmentToolsService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EmailRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_types_share_one_limit_and_resume_after_the_interval(): void
    {
        config(['mail.queue_interval_seconds' => 11]);
        $first = new SendCandidateAccess(1);
        $second = new SendCandidateAccess(2);
        $underlying = Mockery::mock(Job::class);
        $underlying->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => $delay >= 11));
        $second->setJob($underlying);
        $sent = 0;
        $run = function ($job) use (&$sent) {
            app(Pipeline::class)->send($job)->through($job->middleware())->then(function () use (&$sent) {
                $sent++;
            });
        };
        $run($first);
        $run($second);
        $this->assertSame(1, $sent);
        $this->travel(15)->seconds();
        $run($second);
        $this->assertSame(2, $sent);
    }

    public function test_throttled_jobs_have_time_bound_retries_and_separate_exception_limit(): void
    {
        $user = User::factory()->create();
        $delivery = AccessDelivery::create(['user_id' => $user->id, 'email' => $user->email, 'password' => null, 'message' => 'Pesan HR', 'expires_at' => now()->addHours(2)]);
        $job = new SendCandidateAccess($delivery->id);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertEquals($delivery->expires_at, $job->retryUntil());
    }

    public function test_provider_limit_is_logged_without_password_or_smtp_response(): void
    {
        Queue::fake();
        Log::spy();
        $user = User::factory()->create();
        app(RecruitmentToolsService::class)->sendAccess(User::factory()->create(['role' => Role::Admin]), [$user->id], 'Pesan HR untuk kandidat');
        $delivery = AccessDelivery::sole();
        $password = $delivery->password;
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('550 5.7.0 Too many emails per second. Payload: '.$password, 550));
        try {
            (new SendCandidateAccess($delivery->id))->handle();
            $this->fail('Expected rate limit exception');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('membatasi laju', $exception->getMessage());
            $this->assertStringNotContainsString($password, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('retrying', $delivery->fresh()->status);
        $this->assertSame($password, $delivery->fresh()->password);
        Log::shouldHaveReceived('warning')->once()->with('Pengiriman email akses tertunda.', [
            'delivery_id' => $delivery->id,
            'category' => 'provider_rate_limit',
            'exception_type' => \RuntimeException::class,
            'smtp_code' => 550,
        ]);
    }
}
