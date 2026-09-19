<?php

namespace App\Jobs;

use App\Models\EmailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSubmissionEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public ?\DateTimeInterface $retryExpiresAt = null;

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryExpiresAt ?? now()->addDay();
    }

    public function middleware(): array
    {
        return [];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public int $deliveryId)
    {
        $this->retryExpiresAt = now()->addDay();
    }

    /** Retained to safely consume jobs queued before submission emails were removed. */
    public function handle(): void
    {
        EmailDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update(['status' => 'cancelled']);
    }

    public function failed(?\Throwable $exception = null): void
    {
        $this->handle();
    }
}
