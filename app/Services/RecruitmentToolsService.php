<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\SendCandidateAccess;
use App\Models\AccessDelivery;
use App\Models\AuditLog;
use App\Models\RecruitmentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecruitmentToolsService
{
    public function deadlines(User $actor, array $data): int
    {
        app(RecruitmentService::class)->adminReason($actor, $data['reason'] ?? '');
        Validator::make($data, ['position_id' => 'required|exists:positions,id', 'recruitment_period_id' => 'required|exists:recruitment_periods,id', 'type' => 'required|in:portfolio', 'deadline' => 'required|date'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $applications = RecruitmentApplication::where('position_id', $data['position_id'])->where('recruitment_period_id', $data['recruitment_period_id'])->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach ($applications as $application) {
                $submission = $application->submissions()->where('type', $data['type'])->whereIn('status', ['not_started', 'draft', 'revision'])->first();
                if ($submission) {
                    app(SubmissionService::class)->deadline($submission, $actor, $data['reason'], $data['deadline']);
                    $count++;
                }
            }
            if (! $count) {
                throw ValidationException::withMessages(['deadline' => 'Tidak ada pengumpulan yang dapat diperpanjang untuk posisi dan batch ini.']);
            }

            return $count;
        });
    }

    public function sendAccess(User $actor, array $ids, string $message): int
    {
        app(RecruitmentService::class)->adminReason($actor, 'Pengiriman akses kandidat melalui email');
        Validator::make(['ids' => $ids, 'message' => $message], ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'message' => 'required|string|min:5|max:5000'])->validate();
        $this->validateMailer();

        return DB::transaction(function () use ($actor, $ids, $message) {
            $users = User::whereIn('id', $ids)->where('role', Role::Candidate)->where('active', true)->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== count($ids)) {
                throw ValidationException::withMessages(['recipientIds' => 'Ada kandidat tidak aktif atau tidak ditemukan. Pilih ulang penerima.']);
            }
            foreach ($users as $user) {
                if (AccessDelivery::where('user_id', $user->id)->whereIn('status', ['pending', 'processing', 'retrying'])->where('expires_at', '>', now())->exists()) {
                    throw ValidationException::withMessages(['recipientIds' => 'Masih ada email akses dalam antrean untuk '.$user->email.'. Tunggu proses selesai.']);
                }
                $password = app(RecruitmentService::class)->resetAccess($actor, $user, 'Pengiriman password sementara oleh HR');
                $delivery = AccessDelivery::create(['user_id' => $user->id, 'email' => $user->email, 'password' => $password, 'message' => $message, 'expires_at' => $user->fresh()->temporary_password_expires_at]);
                AuditLog::record('account.access_email_queued', $user, $actor, null, ['delivery_id' => $delivery->id]);
                DB::afterCommit(fn () => $this->dispatchAccess($delivery));
            }

            return $users->count();
        });
    }

    public function validateMailer(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true) && ! (app()->environment('testing') && $transport === 'array')) {
            throw ValidationException::withMessages(['message' => 'Konfigurasikan mailer pengiriman (misalnya SMTP) sebelum mengirim email. Mailer log tidak diizinkan.']);
        }
    }

    public function dispatchAccess(AccessDelivery $delivery): void
    {
        try {
            SendCandidateAccess::dispatch($delivery->id);
        } catch (\Throwable) {
            $delivery->update(['status' => 'failed']);
        }
    }
}
