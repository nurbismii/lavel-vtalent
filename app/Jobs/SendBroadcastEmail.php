<?php

namespace App\Jobs;

use App\Models\EmailBroadcastDelivery;
use App\Services\EmailBroadcastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendBroadcastEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public \DateTimeInterface $retryExpiresAt;

    public function __construct(public int $deliveryId)
    {
        $this->retryExpiresAt = now()->addDay();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryExpiresAt;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('portal-emails'))->shared()->releaseAfter(max(1, (int) config('mail.queue_interval_seconds')))->expireAfter(120),
            new RateLimited('portal-emails'),
        ];
    }

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $delivery = EmailBroadcastDelivery::lockForUpdate()->find($this->deliveryId);
                if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
                    return;
                }
                app(EmailBroadcastService::class)->validateDeliveryConfiguration();
                $broadcast = $delivery->broadcast;
                $body = Str::sanitizeHtml($broadcast->body);
                $plainBody = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'], "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $delivery->update(['status' => 'processing']);
                Mail::send(['html' => 'emails.employee-information', 'text' => 'emails.employee-information-text'], [
                    'subject' => $broadcast->subject, 'body' => $body, 'plainBody' => $plainBody,
                ], fn ($mail) => $mail->to($delivery->email)->subject($broadcast->subject));
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'attempts' => $delivery->attempts + 1]);
            });
        } catch (\Throwable $exception) {
            $rateLimited = preg_match('/too many|rate.?limit|throttl|\b429\b/i', $exception->getMessage()) === 1;
            $reason = $rateLimited
                ? 'Layanan email membatasi laju pengiriman. Sistem akan mencoba ulang secara otomatis.'
                : 'Layanan email belum dapat menerima pesan. Periksa koneksi, konfigurasi SMTP, atau alamat penerima. Sistem akan mencoba ulang secara otomatis.';
            EmailBroadcastDelivery::whereKey($this->deliveryId)->whereNotIn('status', ['sent', 'failed'])
                ->increment('attempts', 1, ['status' => 'retrying', 'last_error' => $reason]);
            Log::warning('Pengiriman blast email kandidat tertunda.', [
                'delivery_id' => $this->deliveryId, 'category' => $rateLimited ? 'provider_rate_limit' : 'delivery_failed',
                'exception_type' => $exception::class, 'smtp_code' => (int) $exception->getCode(),
            ]);
            throw new \RuntimeException($reason);
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        EmailBroadcastDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update([
            'status' => 'failed',
            'last_error' => 'Pengiriman otomatis dihentikan karena batas percobaan atau waktu antrean telah tercapai. Periksa layanan email dan antrean, lalu klik Coba ulang.',
        ]);
    }
}
