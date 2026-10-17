<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\FormAccessToken;
use App\Models\FormAccountDispatch;
use App\Models\FormDocument;
use App\Models\FormResponse;
use App\Models\PortfolioExport;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    public function candidateDeactivationSummary(User $actor, int $userId): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->active && ! $actor->must_change_password && $actor->role === Role::Admin && $actor->getAppAuthenticationSecret(), 403);
        $user = User::findOrFail($userId);
        abort_unless($user->role === Role::Candidate, 403);
        $applications = $user->applications()->orderBy('id')->pluck('id')->all();
        $responses = Schema::hasTable('form_responses')
            ? $this->candidateResponses($user, $applications)->orderBy('id')->get(['id', 'form_intake_id', 'email', 'lock_version'])->toArray()
            : [];
        $uploads = UploadedFile::whereIn('recruitment_application_id', $applications)->orderBy('id')->get(['id', 'size']);
        $documents = $responses ? FormDocument::whereIn('form_response_id', array_column($responses, 'id'))->orderBy('id')->get(['id', 'size']) : collect();
        $exports = PortfolioExport::where(function (Builder $query) use ($applications): void {
            $query->whereRaw('1 = 0');
            foreach ($applications as $id) {
                $query->orWhereJsonContains('application_ids', $id);
            }
        })->orderBy('id')->pluck('id')->all();

        return [
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->active,
            'applications' => $applications, 'uploads' => $uploads->pluck('id')->all(),
            'responses' => $responses, 'documents' => $documents->pluck('id')->all(), 'exports' => $exports,
            'bytes' => (int) ($uploads->sum('size') + $documents->sum('size')),
        ];
    }

    private function candidateResponses(User $user, array $applicationIds): Builder
    {
        return FormResponse::where(function (Builder $query) use ($user, $applicationIds): void {
            $query->where('user_id', $user->id)->orWhereIn('recruitment_application_id', $applicationIds)
                ->orWhere(fn (Builder $unlinked) => $unlinked->whereNull('user_id')->whereRaw('LOWER(email) = ?', [Str::lower($user->email)]));
        });
    }

    public function deactivateCandidate(User $actor, int $userId, array $confirmed, string $reason): AuditLog
    {
        $this->adminReason($actor, $reason);

        return DB::transaction(function () use ($actor, $userId, $confirmed, $reason): AuditLog {
            $user = User::lockForUpdate()->findOrFail($userId);
            $applications = $user->applications()->orderBy('id')->lockForUpdate()->get();
            $responses = Schema::hasTable('form_responses') ? $this->candidateResponses($user, $applications->modelKeys())->orderBy('id')->lockForUpdate()->get() : collect();
            $dispatches = Schema::hasTable('form_account_dispatches') ? FormAccountDispatch::whereIn('form_response_id', $responses->modelKeys())->orderBy('id')->lockForUpdate()->get() : collect();
            if ($dispatches->contains(fn ($dispatch) => in_array($dispatch->status, ['pending', 'linked'], true))) {
                throw ValidationException::withMessages(['candidateDeactivation' => 'Penghubungan akun masih diproses. Tunggu proses selesai sebelum menonaktifkan kandidat.']);
            }
            $summary = $this->candidateDeactivationSummary($actor, $userId);
            if ($summary !== $confirmed) {
                throw ValidationException::withMessages(['candidateDeactivation' => 'Data kandidat berubah sejak konfirmasi dibuka. Batalkan lalu buka konfirmasi kembali.']);
            }
            $exports = PortfolioExport::whereKey($summary['exports'])->orderBy('id')->lockForUpdate()->get();
            if ($exports->contains('status', 'processing')) {
                throw ValidationException::withMessages(['candidateDeactivation' => 'ZIP ekspor yang memuat kandidat ini sedang dibuat. Tunggu proses selesai lalu coba kembali.']);
            }
            $files = UploadedFile::whereKey($summary['uploads'])->orderBy('id')->lockForUpdate()->get(['disk', 'path'])->toArray();
            if ($summary['documents']) {
                foreach (FormDocument::whereKey($summary['documents'])->orderBy('id')->lockForUpdate()->get(['path']) as $document) {
                    $files[] = ['disk' => 'private', 'path' => $document->path];
                }
            }
            foreach ($exports as $export) {
                $files[] = ['disk' => 'private', 'path' => $export->path];
                $files[] = ['disk' => 'private', 'path' => $export->path.'.xlsx'];
            }
            $audit = AuditLog::record('candidate.deactivated', $user, $actor, $reason, [...$summary, 'remaining_files' => $files, 'cleanup_status' => 'pending']);
            $this->updateCandidate($actor, $user, ['name' => $user->name, 'email' => $user->email, 'active' => false], $reason);
            AccessDelivery::where('user_id', $user->id)->whereNotIn('status', ['sent', 'cancelled'])->update(['status' => 'cancelled', 'password' => null]);
            UploadedFile::whereKey($summary['uploads'])->update(['size' => 0, 'scan_status' => 'rejected', 'scan_message' => 'File dihapus saat kandidat dinonaktifkan.']);
            if ($responses->isNotEmpty()) {
                FormDocument::whereKey($summary['documents'])->update(['size' => 0, 'selected' => false, 'scan_status' => 'rejected', 'scan_message' => 'File dihapus saat kandidat dinonaktifkan.']);
                FormResponse::whereKey($responses->modelKeys())->increment('access_generation');
            }
            if (Schema::hasTable('form_access_tokens')) {
                FormAccessToken::where(function (Builder $query) use ($user, $responses): void {
                    $query->whereRaw('LOWER(email) = ?', [Str::lower($user->email)]);
                    foreach ($responses as $response) {
                        $query->orWhere(fn (Builder $token) => $token->where('form_intake_id', $response->form_intake_id)->where('email', $response->email));
                    }
                })->update(['expires_at' => now()]);
            }
            PortfolioExport::whereKey($summary['exports'])->update(['status' => 'failed', 'error' => 'Akses ZIP dicabut karena file kandidat telah dihapus.']);
            DB::afterCommit(function () use ($audit): void {
                try {
                    $this->cleanupCandidateFiles($audit->id);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $audit;
        }, 5);
    }

    public function cleanupCandidateFiles(int $auditId): bool
    {
        return DB::transaction(function () use ($auditId): bool {
            $audit = AuditLog::where('action', 'candidate.deactivated')->lockForUpdate()->findOrFail($auditId);
            $metadata = $audit->metadata;
            $remaining = [];
            foreach ($metadata['remaining_files'] ?? [] as $file) {
                try {
                    $disk = Storage::disk($file['disk']);
                    if ($disk->exists($file['path']) && ! $disk->delete($file['path'])) {
                        throw new \RuntimeException('Gagal menghapus file kandidat: '.$file['path']);
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                    $remaining[] = $file;
                }
            }
            $audit->update(['metadata' => [...$metadata, 'remaining_files' => $remaining, 'cleanup_status' => $remaining ? 'pending' : 'completed']]);

            return $remaining === [];
        });
    }

    public function selectByEmails(Builder $query, string $text, array $selectedIds, string $errorField): array
    {
        if (strlen($text) > 30000) {
            throw ValidationException::withMessages([$errorField => 'Maksimal 100 email per paste.']);
        }
        $emails = array_values(array_unique(preg_split('/[\s,;]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $validator = Validator::make(['emails' => $emails], [
            'emails' => 'required|array|min:1|max:100',
            'emails.*' => 'required|email|max:255',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$errorField => 'Paste 1–100 email valid, dipisahkan baris baru, spasi, koma, atau titik koma.']);
        }
        $records = $query->whereIn(DB::raw('LOWER(users.email)'), $emails)->get();
        $missing = array_diff($emails, $records->map(fn ($record) => mb_strtolower($record->email))->all());
        if ($missing) {
            throw ValidationException::withMessages([$errorField => 'Email tidak ditemukan atau kandidat tidak memenuhi syarat/filter: '.implode(', ', $missing)]);
        }
        $ids = array_values(array_unique(array_map('strval', [...$selectedIds, ...$records->pluck('id')->all()])));
        if (count($ids) > 100) {
            throw ValidationException::withMessages([$errorField => 'Maksimal 100 kandidat dipilih.']);
        }

        return $ids;
    }

    public function create(User $actor, array $data): array
    {
        abort_unless($actor->active && $actor->role === Role::Admin, 403);
        $data['email'] = Str::lower(trim($data['email'] ?? ''));
        Validator::make($data, [
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255',
            'position_id' => 'required|exists:positions,id', 'recruitment_period_id' => 'required|exists:recruitment_periods,id',
            'portfolio_deadline' => 'required|date',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            abort_unless(Position::findOrFail($data['position_id'])->active && RecruitmentPeriod::findOrFail($data['recruitment_period_id'])->active, 422, 'Posisi dan periode harus aktif.');
            $password = null;
            $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
            if (! $user) {
                $password = Str::password(20);
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password, 'role' => Role::Candidate, 'must_change_password' => true, 'temporary_password_expires_at' => now()->addHours(AppSetting::valueFor('temporary_password_hours'))]);
                AuditLog::record('account.created', $user, $actor);
            }
            if ($user->role !== Role::Candidate || ! $user->active) {
                throw ValidationException::withMessages(['email' => 'Akun ini tidak dapat digunakan untuk lamaran kandidat.']);
            }
            if (RecruitmentApplication::where('active_user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Kandidat masih memiliki lamaran aktif. Arsipkan lamaran sebelumnya terlebih dahulu.']);
            }
            $app = RecruitmentApplication::create(['user_id' => $user->id, 'active_user_id' => $user->id, 'position_id' => $data['position_id'], 'recruitment_period_id' => $data['recruitment_period_id']]);
            $deadline = Carbon::parse($data['portfolio_deadline'], AppSetting::valueFor('timezone'))->utc();
            if ($deadline->lte(now())) {
                throw ValidationException::withMessages(['portfolio_deadline' => 'Tenggat harus berada di masa depan.']);
            }
            $app->submissions()->create(['type' => SubmissionType::Portfolio, 'deadline' => $deadline]);
            AuditLog::record('application.created', $app, $actor);

            return ['application' => $app, 'password' => $password];
        });
    }

    public function resetAccess(User $actor, User $user, string $reason): string
    {
        $this->adminReason($actor, $reason);
        abort_unless($user->role === Role::Candidate, 403);

        return DB::transaction(function () use ($actor, $user, $reason) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $password = Str::password(20);
            $user->update(['password' => $password, 'must_change_password' => true, 'temporary_password_expires_at' => now()->addHours(AppSetting::valueFor('temporary_password_hours')), 'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            AuditLog::record('account.reset', $user, $actor, $reason);

            return $password;
        });
    }

    public function adminReason(User $actor, string $reason): void
    {
        abort_unless($actor->active && $actor->role === Role::Admin, 403);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
    }

    public function archive(User $actor, RecruitmentApplication $app, string $reason): void
    {
        $this->adminReason($actor, $reason);
        DB::transaction(function () use ($actor, $app, $reason) {
            $app = RecruitmentApplication::lockForUpdate()->findOrFail($app->id);
            $app->update(['active_user_id' => null, 'archived_at' => now()]);
            AuditLog::record('application.archived', $app, $actor, $reason);
        });
    }

    public function updateCandidate(User $actor, User $user, array $data, string $reason): void
    {
        $this->adminReason($actor, $reason);
        abort_unless($user->role === Role::Candidate, 403);
        $data['email'] = Str::lower(trim($data['email'] ?? ''));
        Validator::make($data, ['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'active' => 'required|boolean'])->validate();
        DB::transaction(function () use ($actor, $user, $data, $reason) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $before = $user->only('name', 'email', 'active');
            if ($user->email !== $data['email'] || ! $data['active']) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->session_version++;
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->fill($data)->save();
            AuditLog::record('account.updated', $user, $actor, $reason, ['before' => $before, 'after' => $user->only('name', 'email', 'active')]);
        });
    }
}
