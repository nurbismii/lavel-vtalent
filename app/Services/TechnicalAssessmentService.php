<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RecruitmentApplication;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TechnicalAssessmentService
{
    public const REVIEW_STATUSES = ['Kemampuan terdemonstrasi', 'Terbatas atau perlu klarifikasi', 'Belum terdemonstrasi', 'Administrasi tidak valid'];

    public const RATING_DIMENSIONS = ['Penjelasan proses', 'Disiplin bukti', 'Kontrol risiko', 'Prioritas', 'Adaptasi', 'Komunikasi'];

    public static function available(): bool
    {
        return Schema::hasTable('technical_assessment_attempts');
    }

    /** @return array<string, array<string, mixed>> */
    public function packages(): array
    {
        return json_decode(file_get_contents(resource_path('technical-assessments.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function admin(User $actor): void
    {
        abort_unless($actor->active && $actor->role === Role::Admin && ! $actor->must_change_password, 403);
    }

    public function authorize(User $actor, TechnicalAssessmentAttempt $attempt): void
    {
        $application = $attempt->application;
        abort_unless($actor->active && ! $actor->must_change_password && $actor->role === Role::Candidate && $application->user_id === $actor->id && ! $application->archived_at && ! $application->purged_at, 404);
    }

    /** @param array<string, mixed> $data */
    public function assign(User $actor, array $data): TechnicalAssessmentAttempt
    {
        $this->admin($actor);
        Validator::make($data, [
            'application_id' => ['required', 'integer'], 'form' => ['required', 'in:A,B,A2'],
            'opens_at' => ['required', 'date'], 'deadline' => ['required', 'date', 'after:opens_at', 'after:now'],
            'durations' => ['required', 'array'], 'durations.*' => ['required', 'integer', 'min:60', 'max:7200'],
            'reviewed' => ['accepted'],
        ])->validate();
        $package = $this->packages()[$data['form']];
        if (array_keys($data['durations']) !== array_keys($package['sections'])) {
            throw ValidationException::withMessages(['durations' => 'Isi durasi untuk seluruh tahapan.']);
        }
        foreach ($package['sections'] as $index => &$section) {
            $section['seconds'] = (int) $data['durations'][$index];
        }
        unset($section);

        return DB::transaction(function () use ($actor, $data, $package): TechnicalAssessmentAttempt {
            $application = RecruitmentApplication::whereNull('archived_at')->whereNull('purged_at')->lockForUpdate()->find($data['application_id']);
            if (! $application) {
                throw ValidationException::withMessages(['application_id' => 'Lamaran tidak aktif atau tidak tersedia.']);
            }
            abort_unless($application->user->active && $application->user->role === Role::Candidate, 422);
            $attempt = TechnicalAssessmentAttempt::firstOrCreate([
                'recruitment_application_id' => $application->id, 'form' => $data['form'], 'version' => $package['version'],
            ], ['package' => $package, 'opens_at' => $data['opens_at'], 'deadline' => $data['deadline'], 'answers' => []]);
            if ($attempt->wasRecentlyCreated) {
                AuditLog::record('technical_assessment.assigned', $attempt, $actor, metadata: ['form' => $attempt->form, 'version' => $attempt->version, 'durations' => $data['durations']]);
            }

            return $attempt;
        });
    }

    /** @param array<string, mixed> $payload */
    public function act(User $actor, int $id, string $action, array $payload = []): TechnicalAssessmentAttempt
    {
        return DB::transaction(function () use ($actor, $id, $action, $payload): TechnicalAssessmentAttempt {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            $this->authorize($actor, $attempt);
            $previousSection = $attempt->section_index;
            $this->expire($attempt);
            if ($action === 'view') {
                return $attempt;
            }
            if ($action === 'conditions') {
                abort_unless($attempt->completed_at, 422, 'Catatan akhir tersedia setelah tes selesai.');
                Validator::make($payload, ['conditions' => ['required', 'array:technical_conditions,accommodations,unfinished,other_conditions'], 'conditions.*' => ['nullable', 'string', 'max:2000'], 'confirmation' => ['accepted']])->validate();
                $attempt->update(['conditions' => $payload['conditions']]);
                AuditLog::record('technical_assessment.conditions_saved', $attempt, $actor);

                return $attempt;
            }
            if ($attempt->completed_at || $previousSection !== $attempt->section_index) {
                return $attempt;
            }
            abort_if(now()->lt($attempt->opens_at), 403, 'Jadwal tes belum dibuka.');
            if ($action === 'consent') {
                abort_if($attempt->isWaitingForFormA(), 422, 'Selesaikan Form A terlebih dahulu sebelum memulai Form B atau Form A2.');
                Validator::make($payload, ['consent' => ['accepted']])->validate();
                if (! $attempt->consented_at) {
                    $attempt->update(['consented_at' => now()]);
                    AuditLog::record('technical_assessment.consented', $attempt, $actor);
                }

                return $attempt;
            }
            abort_unless($attempt->consented_at, 422, 'Konfirmasikan aturan pengerjaan sebelum mulai.');
            abort_unless(($payload['section'] ?? null) === $attempt->section_index && ($payload['revision'] ?? null) === $attempt->revision, 409, 'Status berubah di sesi lain. Muat ulang halaman sebelum melanjutkan.');
            if ($action === 'start') {
                $this->start($attempt, $actor);

                return $attempt;
            }
            abort_unless(in_array($action, ['save', 'finish'], true) && $attempt->section_started_at, 409, 'Tahap belum dimulai atau sudah ditutup.');
            $section = $attempt->package['sections'][$attempt->section_index];
            Validator::make($payload, ['answers' => ['present', 'array', 'max:'.count($section['questions'])], 'answers.*' => ['nullable', 'string', 'max:5000']])->validate();
            $questions = collect($section['questions'])->keyBy('id');
            foreach ($payload['answers'] as $key => $value) {
                $question = $questions->get($key);
                abort_unless($question, 422, 'Soal tidak tersedia pada tahap ini.');
                if ($question['type'] === 'choice' && $value !== null && $value !== '') {
                    abort_unless(array_key_exists($value, $question['options']), 422, 'Pilihan jawaban tidak valid.');
                }
            }
            $answers = $attempt->answers ?? [];
            if (($answers[$attempt->section_index] ?? []) !== $payload['answers']) {
                $answers[$attempt->section_index] = $payload['answers'];
                $attempt->update(['answers' => $answers, 'revision' => $attempt->revision + 1]);
                AuditLog::record('technical_assessment.answers_saved', $attempt, $actor, metadata: ['section' => $attempt->section_index, 'revision' => $attempt->revision]);
            }
            if ($action === 'finish') {
                $this->advance($attempt, 'submitted', $actor);
            }

            return $attempt;
        });
    }

    public function control(User $actor, int $id, int $section, int $revision, string $action): TechnicalAssessmentAttempt
    {
        $this->admin($actor);

        return DB::transaction(function () use ($actor, $id, $section, $revision, $action): TechnicalAssessmentAttempt {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            abort_if($attempt->application->archived_at || $attempt->application->purged_at, 422);
            $this->expire($attempt);
            if ($attempt->completed_at || $attempt->section_index !== $section || $attempt->revision !== $revision) {
                return $attempt;
            }
            abort_unless($attempt->form === 'B', 422);
            if ($action === 'start') {
                $this->start($attempt, $actor);
            } else {
                abort_unless($action === 'finish' && $attempt->section_started_at, 422);
                $this->advance($attempt, 'assessor_closed', $actor);
            }

            return $attempt;
        });
    }

    private function start(TechnicalAssessmentAttempt $attempt, User $actor): void
    {
        abort_unless($attempt->consented_at && now()->gte($attempt->opens_at) && now()->lt($attempt->deadline), 422, 'Tunggu konfirmasi kandidat dan jadwal tes dibuka.');
        if ($attempt->section_started_at) {
            return;
        }
        abort_if($attempt->isWaitingForFormA(), 422, 'Selesaikan Form A terlebih dahulu sebelum memulai Form B atau Form A2.');
        $seconds = $attempt->package['sections'][$attempt->section_index]['seconds'];
        $attempt->update(['section_started_at' => now(), 'section_expires_at' => now()->addSeconds($seconds)->min($attempt->deadline), 'revision' => $attempt->revision + 1]);
        AuditLog::record('technical_assessment.section_started', $attempt, $actor, metadata: ['section' => $attempt->section_index, 'expires_at' => $attempt->section_expires_at->toIso8601String()]);
    }

    public function activity(User $actor, int $id, string $event, int $section): void
    {
        Validator::make(compact('event'), ['event' => ['in:page_loaded,tab_hidden,window_blur,fullscreen_exit,connection_restored']])->validate();
        DB::transaction(function () use ($actor, $id, $event, $section): void {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            $this->authorize($actor, $attempt);
            abort_unless(! $attempt->completed_at && $attempt->section_started_at && $attempt->section_index === $section && now()->lt($attempt->section_expires_at) && now()->lt($attempt->deadline), 409);
            AuditLog::record('technical_assessment.activity.'.$event, $attempt, $actor, metadata: ['section' => $section]);
        });
    }

    public function expire(TechnicalAssessmentAttempt $attempt): void
    {
        if ($attempt->completed_at) {
            return;
        }
        if (now()->gte($attempt->deadline)) {
            $this->complete($attempt, 'deadline');
        } elseif ($attempt->section_expires_at && now()->gte($attempt->section_expires_at)) {
            $this->advance($attempt, 'timeout');
        }
    }

    private function advance(TechnicalAssessmentAttempt $attempt, string $reason, ?User $actor = null): void
    {
        AuditLog::record('technical_assessment.section_closed', $attempt, $actor, $reason, ['section' => $attempt->section_index, 'started_at' => $attempt->section_started_at?->toIso8601String()]);
        $attempt->update(['section_index' => $attempt->section_index + 1, 'section_started_at' => null, 'section_expires_at' => null, 'revision' => $attempt->revision + 1]);
        if ($attempt->section_index >= count($attempt->package['sections'])) {
            $this->complete($attempt, $reason);
        }
    }

    private function complete(TechnicalAssessmentAttempt $attempt, string $reason): void
    {
        $answers = [];
        foreach ($attempt->answers ?? [] as $sectionAnswers) {
            $answers = array_replace($answers, $sectionAnswers);
        }
        $items = [];
        foreach ($attempt->package['scoring'] as $id => $weights) {
            $items[$id] = $weights[$answers[$id] ?? ''] ?? 0;
        }
        $scores = ['items' => $items];
        if ($attempt->form === 'A') {
            foreach (['Verbal' => range(1, 4), 'Numerik' => range(5, 8), 'Logis' => range(9, 12)] as $domain => $numbers) {
                $scores[$domain] = array_sum(array_map(fn (int $n): int => $items['A'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)], $numbers));
            }
            $scores['Penalaran'] = $scores['Verbal'] + $scores['Numerik'] + $scores['Logis'];
            $scores['Dokumen'] = array_sum(array_filter($items, fn (string $id): bool => str_starts_with($id, 'D'), ARRAY_FILTER_USE_KEY));
            $scores['SJT'] = array_sum(array_filter($items, fn (string $id): bool => str_starts_with($id, 'S'), ARRAY_FILTER_USE_KEY));
            $scores['IPP pilot'] = round(100 * (0.45 * $scores['Penalaran'] / 12 + 0.35 * $scores['Dokumen'] / 7 + 0.20 * $scores['SJT'] / 20), 2);
            $scores['Perlu verifikasi'] = array_values(array_filter(['D03', 'D04', 'D07'], fn (string $id): bool => $items[$id] === 0));
        } elseif ($attempt->form === 'B') {
            $scores['Penalaran objektif'] = array_sum($items);
        } else {
            foreach ($attempt->package['ipip_factors'] as $factor => $definitions) {
                $values = [];
                foreach ($definitions as $definition) {
                    $value = $answers[$definition['id']] ?? null;
                    if ($value !== null && $value !== '') {
                        $values[] = $definition['reverse'] ? 6 - (int) $value : (int) $value;
                    }
                }
                $scores[$factor] = count($values) === 5 ? array_sum($values) / 5 : null;
            }
        }
        $attempt->update(['scores' => $scores, 'completed_at' => now(), 'section_started_at' => null, 'section_expires_at' => null, 'revision' => $attempt->revision + 1]);
        AuditLog::record('technical_assessment.completed', $attempt, reason: $reason);
    }

    public function extend(User $actor, int $id, int $section, int $revision, int $seconds, string $reason): void
    {
        $this->admin($actor);
        Validator::make(compact('seconds', 'reason'), ['seconds' => ['integer', 'min:60', 'max:7200'], 'reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
        DB::transaction(function () use ($actor, $id, $section, $revision, $seconds, $reason): void {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            abort_unless(! $attempt->completed_at && $attempt->section_started_at && $attempt->section_index === $section && $attempt->revision === $revision && now()->lt($attempt->section_expires_at) && now()->lt($attempt->deadline) && ! $attempt->application->archived_at && ! $attempt->application->purged_at, 409, 'Perpanjangan hanya untuk tahap aktif. Muat ulang status.');
            $old = $attempt->section_expires_at->toIso8601String();
            $expires = $attempt->section_expires_at->copy()->addSeconds($seconds);
            $attempt->update(['section_expires_at' => $expires, 'deadline' => $attempt->deadline->copy()->addSeconds($seconds)]);
            AuditLog::record('technical_assessment.time_extended', $attempt, $actor, $reason, ['section' => $section, 'seconds' => $seconds, 'previous_expires_at' => $old]);
        });
    }

    public function deleteAttempt(User $actor, int $id): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $id): void {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            abort_if($attempt->application->purged_at, 404);
            AuditLog::record('technical_assessment.attempt_deleted', $attempt, $actor, metadata: [
                'recruitment_application_id' => $attempt->recruitment_application_id,
                'form' => $attempt->form,
                'version' => $attempt->version,
            ]);
            $attempt->delete();
        });
    }

    /** @param array<string, mixed> $review */
    public function review(User $actor, int $id, array $review): void
    {
        $this->admin($actor);
        Validator::make($review, ['status' => ['required', 'in:'.implode(',', self::REVIEW_STATUSES)], 'notes' => ['required', 'string', 'min:10', 'max:10000'], 'ratings' => ['present', 'array'], 'ratings.*' => ['required', 'integer', 'between:0,4']])->validate();
        DB::transaction(function () use ($actor, $id, $review): void {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            abort_unless($attempt->completed_at && ! $attempt->application->purged_at, 422, 'Selesaikan tes sebelum memberi penilaian.');
            if ($attempt->form === 'B' && (count($review['ratings']) !== count(self::RATING_DIMENSIONS) || array_diff(self::RATING_DIMENSIONS, array_keys($review['ratings'])))) {
                throw ValidationException::withMessages(['ratings' => 'Lengkapi enam dimensi penilaian Form B.']);
            }
            $attempt->update(['review' => ['status' => $review['status'], 'notes' => $review['notes'], 'ratings' => $review['ratings'], 'assessor_id' => $actor->id, 'reviewed_at' => now()->toIso8601String()]]);
            AuditLog::record('technical_assessment.reviewed', $attempt, $actor, metadata: ['status' => $review['status']]);
        });
    }
}
