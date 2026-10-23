<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\SendBroadcastEmail;
use App\Models\AuditLog;
use App\Models\EmailBroadcast;
use App\Models\EmailBroadcastDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailBroadcastService
{
    /** @return array{emails: list<string>, subject: string, body: string} */
    public function prepare(array $data): array
    {
        Validator::make(['data' => $data], [
            'data.recipients' => 'required|string|max:30000',
            'data.subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'data.body' => 'required|string|max:50000',
        ])->validate();

        $emails = array_values(array_unique(preg_split('/[\s,;]+/u', mb_strtolower(trim($data['recipients'])), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $validator = Validator::make(['emails' => $emails], [
            'emails' => 'required|array|min:1|max:100',
            'emails.*' => 'required|email:rfc|max:255',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['data.recipients' => 'Paste 1–100 email valid dari kolom email Excel. Pisahkan dengan baris baru, tab, spasi, koma, atau titik koma. Periksa kembali alamat yang salah.']);
        }

        $body = Str::sanitizeHtml($data['body']);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim(str_replace("\u{00A0}", ' ', $text)) === '') {
            throw ValidationException::withMessages(['data.body' => 'Isi pesan tidak boleh kosong.']);
        }

        return ['emails' => $emails, 'subject' => trim($data['subject']), 'body' => $body];
    }

    public function validateDeliveryConfiguration(): void
    {
        app(RecruitmentToolsService::class)->validateMailer();
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (! app()->environment('testing') && ! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['delivery' => 'Gunakan antrean database, Redis, SQS, atau Beanstalkd dan jalankan queue worker sebelum mengirim blast email.']);
        }
    }

    /** @param array{emails: list<string>, subject: string, body: string} $content */
    public function queue(User $actor, array $content, string $submissionKey): EmailBroadcast
    {
        $this->authorizeAdmin($actor);
        Validator::make(['submission_key' => $submissionKey], ['submission_key' => 'required|uuid'])->validate();
        $content = $this->prepare(['recipients' => implode("\n", $content['emails']), 'subject' => $content['subject'], 'body' => $content['body']]);
        $this->validateDeliveryConfiguration();

        return DB::transaction(function () use ($actor, $content, $submissionKey): EmailBroadcast {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = EmailBroadcast::where('submission_key', $submissionKey)->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id, 403);

                return $existing;
            }

            $broadcast = EmailBroadcast::create([
                'created_by' => $actor->id, 'submission_key' => $submissionKey,
                'subject' => $content['subject'], 'body' => $content['body'],
            ]);
            foreach ($content['emails'] as $email) {
                $delivery = $broadcast->deliveries()->create(['email' => $email]);
                DB::afterCommit(fn () => $this->dispatch($delivery));
            }
            AuditLog::record('employee_email.queued', $broadcast, $actor, null, ['recipients' => count($content['emails'])]);

            return $broadcast;
        });
    }

    public function retry(User $actor, int $deliveryId): void
    {
        $this->authorizeAdmin($actor);
        $this->validateDeliveryConfiguration();
        DB::transaction(function () use ($actor, $deliveryId): void {
            $delivery = EmailBroadcastDelivery::lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->status !== 'failed') {
                throw ValidationException::withMessages(['delivery' => 'Hanya email berstatus gagal yang dapat dicoba ulang.']);
            }
            $delivery->update(['status' => 'pending', 'last_error' => null]);
            AuditLog::record('employee_email.retried', $delivery, $actor);
            DB::afterCommit(fn () => $this->dispatch($delivery));
        });
    }

    private function dispatch(EmailBroadcastDelivery $delivery): void
    {
        try {
            SendBroadcastEmail::dispatch($delivery->id);
        } catch (\Throwable) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Email belum dapat dimasukkan ke antrean. Periksa layanan antrean, lalu coba ulang.']);
        }
    }

    private function authorizeAdmin(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->active && $actor->role === Role::Admin && ! $actor->must_change_password && $actor->getAppAuthenticationSecret(), 403);
    }
}
