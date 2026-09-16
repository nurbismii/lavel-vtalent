<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Jobs\SendSubmissionEmail;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\RecruitmentApplication;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Models\UploadedFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmissionService
{
    public function locked(Submission $submission): Submission
    {
        RecruitmentApplication::whereKey($submission->recruitment_application_id)->lockForUpdate()->firstOrFail();

        return Submission::lockForUpdate()->findOrFail($submission->id);
    }

    public function draft(Submission $submission, User $actor): SubmissionVersion
    {
        return DB::transaction(function () use ($submission, $actor) {
            $submission = $this->locked($submission);
            Gate::forUser($actor)->authorize('update', $submission);

            return $this->draftFor($submission);
        });
    }

    private function draftFor(Submission $submission): SubmissionVersion
    {
        $draft = $submission->versions()->where('status', 'draft')->first();
        if (! $draft) {
            $draft = $submission->versions()->create(['number' => ($submission->versions()->max('number') ?? 0) + 1, 'links' => []]);
        }

        return $draft;
    }

    public function save(Submission $submission, User $actor, int $versionId, array $data, bool $final = false): SubmissionVersion
    {
        return DB::transaction(function () use ($submission, $actor, $versionId, $data, $final) {
            $submission = $this->locked($submission);
            Gate::forUser($actor)->authorize('view', $submission);
            abort_unless($actor->role === Role::Candidate, 403);
            $version = $submission->versions()->findOrFail($versionId);
            if ($final && $version->status === 'final') {
                return $version;
            }
            Gate::forUser($actor)->authorize('update', $submission);
            abort_unless($version->status === 'draft', 409);
            Validator::make($data, [
                'notes' => 'nullable|string|max:5000', 'links' => 'array|max:5', 'links.*.label' => 'required|string|max:120',
                'links.*.url' => 'required|url:http,https|max:2048', 'attachments' => 'array|max:11',
                'attachments.*.id' => 'required|integer|distinct', 'attachments.*.description' => 'nullable|string|max:500',
            ])->validate();
            $attachments = $data['attachments'] ?? [];
            $ids = array_column($attachments, 'id');
            $files = UploadedFile::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $counts = [];
            foreach ($attachments as $item) {
                $file = $files->get($item['id']);
                abort_unless($file && $file->submission_id === $submission->id && $file->uploader_id === $actor->id, 404);
                $allowed = $submission->type === SubmissionType::Portfolio ? ['portfolio_main', 'portfolio_evidence'] : ['technical_result'];
                abort_unless(in_array($file->purpose, $allowed, true), 422);
                $counts[$file->purpose] = ($counts[$file->purpose] ?? 0) + 1;
                $limits = AppSetting::valueFor('uploads')[$file->purpose];
                if ($counts[$file->purpose] > $limits['max_files']) {
                    throw ValidationException::withMessages(['attachments' => 'Jumlah file melebihi batas pengumpulan.']);
                }
                if ($final && ! $file->scan_status->available()) {
                    throw ValidationException::withMessages(['attachments' => 'Semua file harus lolos pemeriksaan keamanan sebelum dikirim final.']);
                }
                if ($final && $file->purpose === 'portfolio_evidence' && ! trim($item['description'] ?? '')) {
                    throw ValidationException::withMessages(['attachments' => 'Isi keterangan untuk setiap bukti tambahan.']);
                }
            }
            $mainRequired = $submission->type === SubmissionType::Portfolio ? 'portfolio_main' : 'technical_result';
            if ($final && ($counts[$mainRequired] ?? 0) < 1) {
                throw ValidationException::withMessages(['attachments' => 'Dokumen utama portofolio atau minimal satu file hasil tes wajib diunggah.']);
            }
            // Keep the old main document until the replacement has passed scanning.
            $oldMain = $version->attachments()->where('purpose', 'portfolio_main')->first();
            if ($oldMain && ! in_array($oldMain->uploaded_file_id, $ids, true)) {
                $newMain = $files->firstWhere('purpose', 'portfolio_main');
                if ($newMain && ! $newMain->scan_status->available()) {
                    throw ValidationException::withMessages(['attachments' => 'Dokumen pengganti belum siap. Dokumen sebelumnya tetap tersimpan.']);
                }
            }
            $version->update(['notes' => $data['notes'] ?? null, 'links' => $data['links'] ?? []]);
            $version->attachments()->delete();
            foreach ($attachments as $index => $item) {
                $version->attachments()->create(['uploaded_file_id' => $item['id'], 'purpose' => $files[$item['id']]->purpose, 'description' => $item['description'] ?? null, 'sort_order' => $index]);
            }
            if ($final) {
                if (now()->gt($submission->deadline)) {
                    throw ValidationException::withMessages(['attachments' => 'Tenggat terlewati sebelum finalisasi selesai.']);
                }
                $version->update(['status' => 'final', 'submitted_at' => now(), 'receipt' => 'VDNI-'.Str::upper(Str::ulid())]);
                $submission->update(['status' => SubmissionStatus::Submitted, 'current_version_id' => $version->id]);
                AuditLog::record('submission.finalized', $submission, $actor, null, ['version' => $version->number, 'receipt' => $version->receipt]);
                $delivery = EmailDelivery::create(['submission_id' => $submission->id, 'submission_version_id' => $version->id, 'event' => 'finalized']);
                DB::afterCommit(function () use ($delivery): void {
                    try {
                        SendSubmissionEmail::dispatch($delivery->id);
                    } catch (\Throwable) {
                        $delivery->update(['status' => 'failed']);
                    }
                });
            } elseif ($submission->status !== SubmissionStatus::Revision) {
                $submission->update(['status' => SubmissionStatus::Draft]);
            }

            return $version->fresh('attachments.file');
        });
    }

    public function revise(Submission $submission, User $actor, string $reason, string $deadline): void
    {
        app(RecruitmentService::class)->adminReason($actor, $reason);
        $date = Carbon::parse($deadline, AppSetting::valueFor('timezone'))->utc();
        if ($date->isPast()) {
            throw ValidationException::withMessages(['deadline' => 'Tenggat revisi harus di masa depan.']);
        }
        DB::transaction(function () use ($submission, $actor, $reason, $date) {
            $submission = $this->locked($submission);
            abort_unless(! $submission->application->archived_at && $submission->status === SubmissionStatus::Submitted, 422);
            $old = $submission->currentVersion;
            $draft = $this->draftFor($submission);
            $draft->update(['notes' => $old->notes, 'links' => $old->links]);
            foreach ($old->attachments as $file) {
                $draft->attachments()->create($file->only('uploaded_file_id', 'purpose', 'description', 'sort_order'));
            }
            $submission->update(['status' => SubmissionStatus::Revision, 'deadline' => $date, 'administrative_reason' => $reason]);
            AuditLog::record('submission.revision_opened', $submission, $actor, $reason, ['deadline' => $date->toIso8601String(), 'version' => $draft->number]);
            $delivery = EmailDelivery::create(['submission_id' => $submission->id, 'submission_version_id' => $draft->id, 'event' => 'revision']);
            DB::afterCommit(function () use ($delivery): void {
                try {
                    SendSubmissionEmail::dispatch($delivery->id);
                } catch (\Throwable) {
                    $delivery->update(['status' => 'failed']);
                }
            });
        });
    }

    public function deadline(Submission $submission, User $actor, string $reason, string $deadline): void
    {
        app(RecruitmentService::class)->adminReason($actor, $reason);
        $date = Carbon::parse($deadline, AppSetting::valueFor('timezone'))->utc();
        DB::transaction(function () use ($submission, $actor, $reason, $date) {
            $submission = $this->locked($submission);
            if ($submission->application->archived_at || ! in_array($submission->status, [SubmissionStatus::NotStarted, SubmissionStatus::Draft, SubmissionStatus::Revision], true) || ! $date->gt($submission->deadline) || ! $date->isFuture()) {
                throw ValidationException::withMessages(['deadline' => 'Perpanjangan harus melewati tenggat lama, di masa depan, dan pengumpulan belum final.']);
            }
            $before = $submission->deadline->toIso8601String();
            $submission->update(['deadline' => $date]);
            AuditLog::record('submission.deadline_changed', $submission, $actor, $reason, ['before' => $before, 'after' => $date->toIso8601String()]);
        });
    }

    public function exempt(Submission $submission, User $actor, string $reason): void
    {
        app(RecruitmentService::class)->adminReason($actor, $reason);
        DB::transaction(function () use ($submission, $actor, $reason) {
            $submission = $this->locked($submission);
            abort_unless(! $submission->application->archived_at && $submission->type === SubmissionType::Portfolio && in_array($submission->status, [SubmissionStatus::NotStarted, SubmissionStatus::Draft], true), 422);
            $submission->update(['status' => SubmissionStatus::Exempt, 'administrative_reason' => $reason]);
            AuditLog::record('submission.exempted', $submission, $actor, $reason);
        });
    }
}
