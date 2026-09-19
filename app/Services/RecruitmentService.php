<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    public function create(User $actor, array $data): array
    {
        abort_unless($actor->active && $actor->role === Role::Admin, 403);
        $data['email'] = Str::lower(trim($data['email'] ?? ''));
        Validator::make($data, [
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255',
            'position_id' => 'required|exists:positions,id', 'recruitment_period_id' => 'required|exists:recruitment_periods,id',
            'portfolio_deadline' => 'required|date', 'test_deadline' => 'required|date',
            'task_label' => 'required|string|max:255', 'instructions' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            abort_unless(Position::findOrFail($data['position_id'])->active && RecruitmentPeriod::findOrFail($data['recruitment_period_id'])->active, 422, 'Posisi dan periode harus aktif.');
            $password = null;
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
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
            foreach (SubmissionType::cases() as $type) {
                $deadline = Carbon::parse($data[$type === SubmissionType::Portfolio ? 'portfolio_deadline' : 'test_deadline'], AppSetting::valueFor('timezone'))->utc();
                if ($deadline->isPast()) {
                    throw ValidationException::withMessages(['portfolio_deadline' => 'Tenggat harus berada di masa depan.']);
                }
                if ($type === SubmissionType::TechnicalTest) {
                    $task = $app->technicalTask();
                    if ($task && $deadline->lte($task->starts_at)) {
                        throw ValidationException::withMessages(['test_deadline' => 'Tenggat tes harus setelah jadwal mulai soal untuk posisi dan batch ini.']);
                    }
                }
                $app->submissions()->create(['type' => $type, 'deadline' => $deadline, 'task_label' => $type === SubmissionType::TechnicalTest ? $data['task_label'] : null, 'instructions' => $type === SubmissionType::TechnicalTest ? ($data['instructions'] ?? null) : null]);
            }
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
