<?php

namespace App\Jobs;

use App\Models\EmailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendSubmissionEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = EmailDelivery::with(['submission.application.user', 'version'])->findOrFail($this->deliveryId);
        if ($delivery->status === 'sent') {
            return;
        }
        $delivery->increment('attempts');
        $delivery->update(['status' => 'processing']);
        $submission = $delivery->submission;
        $text = $delivery->event === 'finalized' ? 'Pengumpulan '.$submission->type->label().' berhasil. Tanda terima: '.$delivery->version?->receipt : 'HR membuka revisi '.$submission->type->label().'. Silakan masuk ke portal untuk melihat alasan dan tenggat revisi.';
        try {
            Mail::raw($text."\n\n".route('candidate.submission', $submission), fn ($message) => $message->to($submission->application->user->email)->subject('VDNi · '.$submission->type->label()));
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $exception) {
            $delivery->update(['status' => 'retrying']);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        EmailDelivery::whereKey($this->deliveryId)->update(['status' => 'failed']);
    }
}
