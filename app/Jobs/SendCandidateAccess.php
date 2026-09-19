<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\RecruitmentToolsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCandidateAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function retryUntil(): \DateTimeInterface
    {
        return AccessDelivery::find($this->deliveryId)?->expires_at ?? now()->addHour();
    }

    public function middleware(): array
    {
        if ($this->job instanceof SyncJob) {
            return [];
        }

        return [
            (new WithoutOverlapping('portal-emails'))->shared()->releaseAfter(max(1, (int) config('mail.queue_interval_seconds')))->expireAfter(120),
            new RateLimited('portal-emails'),
        ];
    }

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        try {
            DB::transaction(function () {
                $record = AccessDelivery::findOrFail($this->deliveryId);
                $user = User::lockForUpdate()->findOrFail($record->user_id);
                $delivery = AccessDelivery::lockForUpdate()->findOrFail($record->id);
                if (in_array($delivery->status, ['sent', 'cancelled'], true)) {
                    return;
                }
                if (! $user->active || $user->role !== Role::Candidate || ! $user->must_change_password || $delivery->expires_at->lte(now()) || $user->email !== $delivery->email || ! $delivery->password || ! Hash::check($delivery->password, $user->password)) {
                    $delivery->update(['status' => 'cancelled', 'password' => null]);

                    return;
                }
                app(RecruitmentToolsService::class)->validateMailer();
                $delivery->update(['status' => 'processing']);
                Mail::send(['html' => 'emails.candidate-access', 'text' => 'emails.candidate-access-text'], [
                    'name' => $user->name,
                    'email' => $delivery->email,
                    'hrMessage' => $delivery->message,
                    'temporaryPassword' => $delivery->password,
                    'expiresAt' => $delivery->expires_at->copy()->timezone(AppSetting::valueFor('timezone'))->format('d M Y H:i T'),
                    'loginUrl' => route('login'),
                ], fn ($mail) => $mail->to($delivery->email)->subject('Akses akun kandidat • VDNI'));
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'password' => null, 'attempts' => $delivery->attempts + 1]);
            });
        } catch (\Throwable $exception) {
            AccessDelivery::whereKey($this->deliveryId)->increment('attempts', 1, ['status' => 'retrying']);
            $rateLimited = str_contains(strtolower($exception->getMessage()), 'too many emails per second');
            Log::warning('Pengiriman email akses tertunda.', [
                'delivery_id' => $this->deliveryId,
                'category' => $rateLimited ? 'provider_rate_limit' : 'delivery_failed',
                'exception_type' => $exception::class,
                'smtp_code' => (int) $exception->getCode(),
            ]);
            throw new \RuntimeException($rateLimited
                ? 'Layanan email membatasi laju pengiriman. Email akan dicoba ulang sesuai antrean.'
                : 'Pengiriman akses gagal. Periksa konfigurasi email lalu coba kembali.');
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        AccessDelivery::whereKey($this->deliveryId)->whereNotIn('status', ['sent', 'cancelled'])->update(['status' => 'failed']);
    }
}
