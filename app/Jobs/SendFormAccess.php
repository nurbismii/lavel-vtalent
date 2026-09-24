<?php

namespace App\Jobs;

use App\Models\FormAccessToken;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;

class SendFormAccess implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function retryUntil(): \DateTimeInterface
    {
        return FormAccessToken::find($this->tokenId)?->expires_at ?? now();
    }

    public function __construct(public int $tokenId, public string $rawToken) {}

    public function middleware(): array
    {
        return [new RateLimited('portal-emails')];
    }

    public function handle(): void
    {
        $token = FormAccessToken::find($this->tokenId);
        if (! $token || $token->used_at || $token->expires_at->isPast()) {
            return;
        }
        Mail::send('emails.form-access', ['url' => route('forms.verify', $this->rawToken), 'expires' => $token->expires_at], function ($message) use ($token) {
            $message->to($token->email)->subject('Verifikasi email dan lanjutkan formulir kandidat');
        });
    }
}
