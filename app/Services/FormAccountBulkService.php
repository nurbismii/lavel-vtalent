<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\LinkFormCandidateAccount;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\FormAccountDispatch;
use App\Models\FormResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FormAccountBulkService
{
    public static function available(): bool
    {
        return CandidateFormService::available() && Schema::hasTable('form_account_dispatches');
    }

    public function validateApplication(array $data): array
    {
        $data = Validator::make($data, [
            'portfolio_deadline' => 'required|date', 'test_deadline' => 'required|date',
            'task_label' => 'required|string|max:255', 'instructions' => 'nullable|string|max:5000',
        ])->validate();
        foreach (['portfolio_deadline', 'test_deadline'] as $key) {
            if (Carbon::parse($data[$key], AppSetting::valueFor('timezone'))->lte(now())) {
                throw ValidationException::withMessages([$key => 'Tenggat harus berada di masa depan pada zona waktu portal.']);
            }
        }

        return $data;
    }

    public function start(User $actor, array $ids, array $application): int
    {
        $this->authorize($actor);
        Validator::make(['ids' => $ids], ['ids' => 'required|array|max:10000', 'ids.*' => 'integer|distinct'])->validate();
        $application = $this->validateApplication($application);
        $count = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $count += DB::transaction(function () use ($actor, $chunk, $application) {
                $queued = 0;
                $responses = FormResponse::whereKey($chunk)->orderBy('id')->lockForUpdate()->get();
                foreach ($responses as $response) {
                    if ($response->user_id || $response->status !== 'submitted' || ! $response->email_verified_at || ! $response->submitted_at || $response->accountDispatch()->exists()) {
                        continue;
                    }
                    $dispatch = FormAccountDispatch::create(['form_response_id' => $response->id, 'actor_id' => $actor->id, 'application_data' => $application]);
                    DB::afterCommit(fn () => $this->dispatch($dispatch));
                    $queued++;
                }

                return $queued;
            }, 5);
        }
        AuditLog::record('form.bulk_accounts_queued', $actor, $actor, null, ['count' => $count]);

        return $count;
    }

    private function authorize(User $actor): void
    {
        abort_unless(self::available(), 404);
        app(CandidateFormService::class)->admin($actor);
        app(RecruitmentToolsService::class)->validateMailer();
        if (! app()->environment('testing') && ! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['bulk' => 'Gunakan antrean database atau antrean asinkron lain sebelum mengirim massal.']);
        }
    }

    public function dispatch(FormAccountDispatch $dispatch): void
    {
        try {
            LinkFormCandidateAccount::dispatch($dispatch->id);
        } catch (\Throwable $exception) {
            report($exception);
            $dispatch->update(['status' => 'failed', 'error' => 'Gagal memasukkan proses ke antrean. Silakan coba ulang.']);
        }
    }

    public function retry(User $actor, int $id, array $application): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $id, $application) {
            $dispatch = FormAccountDispatch::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($dispatch->deliveryStatus(), ['failed', 'cancelled'], true), 422);
            // Keep the same delivery/password on mail retries when it is still valid.
            if ($dispatch->delivery && $dispatch->delivery->status === 'failed' && $dispatch->delivery->expires_at->gt(now()) && $dispatch->delivery->password) {
                $dispatch->delivery->update(['status' => 'pending']);
                $dispatch->update(['status' => 'queued', 'error' => null]);
                DB::afterCommit(fn () => app(RecruitmentToolsService::class)->dispatchAccess($dispatch->delivery));
            } else {
                $dispatch->update(['actor_id' => $actor->id, 'application_data' => $dispatch->linked_at ? $dispatch->application_data : $this->validateApplication($application), 'status' => 'pending', 'access_delivery_id' => null, 'error' => null]);
                DB::afterCommit(fn () => $this->dispatch($dispatch));
            }
            AuditLog::record('form.bulk_account_retried', $dispatch, $actor);
        }, 5);
    }

    public function process(int $id): void
    {
        DB::transaction(function () use ($id) {
            $dispatch = FormAccountDispatch::lockForUpdate()->findOrFail($id);
            if (in_array($dispatch->status, ['queued', 'sent', 'skipped'], true)) {
                return;
            }
            abort_unless($dispatch->actor, 403);
            $this->authorize($dispatch->actor);
            $response = FormResponse::lockForUpdate()->findOrFail($dispatch->form_response_id);
            if (! $dispatch->linked_at) {
                if ($response->user_id || $response->status !== 'submitted' || ! $response->email_verified_at) {
                    $dispatch->update(['status' => 'skipped', 'error' => 'Respons telah terhubung atau belum siap diproses.']);

                    return;
                }
                $response = app(CandidateFormService::class)->link($dispatch->actor, $response, $dispatch->application_data);
                $dispatch->update(['linked_at' => now(), 'status' => 'linked']);
            } else {
                $dispatch->update(['status' => 'linked']);
            }
        }, 5);

        DB::transaction(function () use ($id) {
            $dispatch = FormAccountDispatch::lockForUpdate()->findOrFail($id);
            if ($dispatch->status !== 'linked') {
                return;
            }
            $this->authorize($dispatch->actor);
            $response = FormResponse::lockForUpdate()->findOrFail($dispatch->form_response_id);
            $user = User::lockForUpdate()->findOrFail($response->user_id);
            abort_unless($user->active && $user->role === Role::Candidate && $user->email === $response->email, 422);
            if ($user->must_change_password && $response->activation_pending) {
                $delivery = AccessDelivery::where('user_id', $user->id)->whereIn('status', ['pending', 'processing', 'retrying', 'sent'])->where('expires_at', '>', now())->latest('id')->first();
                $delivery ??= app(CandidateFormService::class)->sendCandidateAccount($dispatch->actor, $response);
                $dispatch->update(['status' => 'queued', 'access_delivery_id' => $delivery->id, 'error' => null]);
            } else {
                Mail::send('emails.form-account-linked', ['name' => $user->name, 'loginUrl' => route('login')], fn ($mail) => $mail->to($user->email)->subject('Formulir terhubung ke akun kandidat • VDNI'));
                $dispatch->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            }
            AuditLog::record('form.bulk_account_processed', $response, $dispatch->actor, null, ['dispatch_id' => $dispatch->id]);
        }, 5);
    }
}
