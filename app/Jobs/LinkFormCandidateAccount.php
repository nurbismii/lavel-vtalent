<?php

namespace App\Jobs;

use App\Models\FormAccountDispatch;
use App\Services\FormAccountBulkService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LinkFormCandidateAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $retryDeadline;

    public function __construct(public int $dispatchId)
    {
        $this->retryDeadline = now()->addDays(7)->timestamp;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryDeadline);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('portal-emails'))->shared()->releaseAfter(max(1, (int) config('mail.queue_interval_seconds')))->expireAfter(120), new RateLimited('portal-emails')];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(FormAccountBulkService $service): void
    {
        try {
            $service->process($this->dispatchId);
        } catch (ValidationException $exception) {
            $this->markFailed($exception->validator->errors()->first());
        } catch (HttpException $exception) {
            $this->markFailed('Akun, posisi, periode, atau izin HR tidak lagi memenuhi syarat. Periksa data sebelum mencoba ulang.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->markFailed('Proses gagal. Periksa konflik akun, konfigurasi email, dan antrean lalu coba ulang.');
    }

    private function markFailed(string $message): void
    {
        FormAccountDispatch::whereKey($this->dispatchId)->whereNotIn('status', ['queued', 'sent', 'skipped'])->update(['status' => 'failed', 'error' => $message]);
    }
}
