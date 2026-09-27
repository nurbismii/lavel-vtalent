<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\RecruitmentApplication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PsychometricService
{
    public static function available(): bool
    {
        return Schema::hasTable('psychometric_attempts');
    }

    public function admin(User $actor): void
    {
        abort_unless($actor->active && $actor->role === Role::Admin && ! $actor->must_change_password, 403);
    }

    public function authorize(User $actor, PsychometricAttempt $attempt): void
    {
        $application = $attempt->application;
        abort_unless($actor->active && ! $actor->must_change_password && $actor->role === Role::Candidate && $application->user_id === $actor->id && ! $application->archived_at && ! $application->purged_at && $attempt->test->published_at, 404);
    }

    /** @param array<int, mixed> $durations
     * @param  array<int, string>  $keyText
     */
    public function configure(User $actor, int $id, array $durations, array $keyText, bool $publish, bool $reviewed): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $id, $durations, $keyText, $publish, $reviewed): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($id);
            abort_if($test->published_at, 409, 'Tes terbit tidak dapat diubah.');
            Validator::make(['keys' => $keyText], ['keys' => ['array', 'max:4'], 'keys.*' => ['nullable', 'string', 'max:500']])->validate();
            $sections = $test->sections;
            $keys = [];
            foreach ($sections as $i => &$section) {
                $section['images'] = $test->sections[$i]['images'] ?? [];
                foreach (collect($section['images'])->flatten() as $image) {
                    if ($publish && ! Storage::disk('private')->exists($image)) {
                        throw ValidationException::withMessages(['reviewed' => 'Gambar pengganti soal belum tersedia. Unggah ulang sebelum menerbitkan.']);
                    }
                }
                $duration = $durations[$i] ?? null;
                Validator::make(['duration' => $duration], ['duration' => [$publish ? 'required' : 'nullable', 'integer', 'min:1', 'max:7200']], ['duration.required' => 'Durasi setiap bagian wajib diisi sebelum terbit.'])->validate();
                $section['seconds'] = $duration === null || $duration === '' ? null : (int) $duration;
                $text = trim($keyText[$i] ?? '');
                $keys[$i] = [];
                if ($text !== '') {
                    foreach (preg_split('/\s+/', strtoupper($text)) as $value) {
                        if ($value === '-') {
                            if ($publish) {
                                throw ValidationException::withMessages(['keyText.'.$i => 'Lengkapi semua kunci, termasuk soal nomor 10, sebelum menerbitkan.']);
                            }
                            $keys[$i][] = [];

                            continue;
                        }
                        $answer = explode(',', $value);
                        $this->validateChoice($answer, $section, true);
                        sort($answer);
                        $keys[$i][] = $answer;
                    }
                }
                if (($publish || $text !== '') && count($keys[$i]) !== $section['count']) {
                    throw ValidationException::withMessages(['keyText.'.$i => 'Jumlah kunci harus '.$section['count'].' sesuai urutan soal.']);
                }
                foreach ([$section['example'], ...$section['pages']] as $page) {
                    if ($publish && ! is_file($this->assetPath($page, $test))) {
                        throw ValidationException::withMessages(['reviewed' => 'Gambar soal belum tersedia.']);
                    }
                }
            }
            unset($section);
            if ($publish && ! $reviewed) {
                throw ValidationException::withMessages(['reviewed' => 'Tinjau gambar, penomoran, kunci dan durasi sebelum menerbitkan.']);
            }
            $review = $test->answer_key_review;
            if ($publish && $review) {
                $review['status'] = 'hr_reviewed';
                $review['reviewed_by'] = $actor->id;
                $review['reviewed_at'] = now()->toIso8601String();
            }
            $test->update(['sections' => $sections, 'answer_key' => $keys, 'answer_key_review' => $review, 'published_at' => $publish ? now() : null]);
            AuditLog::record($publish ? 'psychometric.published' : 'psychometric.configured', $test, $actor);
        });
    }

    public function suggestKey(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($id);
            if ($test->published_at || collect($test->answer_key ?? [])->flatten()->isNotEmpty()) {
                throw ValidationException::withMessages(['test' => 'Usulan hanya dapat mengisi paket draf yang kuncinya masih kosong.']);
            }
            if ($test->corrected_page_path || collect($test->sections)->contains(fn (array $section): bool => ! empty($section['images'])) || $test->attempts()->exists()) {
                throw ValidationException::withMessages(['test' => 'Paket dengan gambar pengganti atau penugasan tidak dapat diisi otomatis.']);
            }
            $proposal = json_decode(file_get_contents(resource_path('psychometrics/cfit-b/suggested-key.json')), true, 512, JSON_THROW_ON_ERROR);
            array_splice($proposal[2], 9, 0, [['answer' => [], 'confidence' => 'belum ditetapkan', 'reason' => 'Soal 10 dipulihkan dari gambar final pengguna; kunci perlu diisi HR.']]);
            $keys = [];
            if (count($test->sections) !== count($proposal)) {
                throw ValidationException::withMessages(['test' => 'Struktur paket tidak sesuai dengan sumber usulan.']);
            }
            foreach ($test->sections as $index => $section) {
                $expected = config('psychometrics.sections')[$index];
                foreach (['count', 'choices', 'options', 'pages', 'source_numbers'] as $field) {
                    if (($section[$field] ?? null) !== ($expected[$field] ?? null)) {
                        throw ValidationException::withMessages(['test' => 'Struktur paket tidak sesuai dengan sumber usulan.']);
                    }
                }
                if (count($proposal[$index]) !== $section['count']) {
                    throw ValidationException::withMessages(['test' => 'Jumlah usulan tidak sesuai jumlah soal.']);
                }
                foreach ($proposal[$index] as $item) {
                    if ($item['answer'] !== []) {
                        $this->validateChoice($item['answer'], $section, true);
                    }
                    $keys[$index][] = $item['answer'];
                }
            }
            $test->update([
                'answer_key' => $keys,
                'answer_key_review' => ['source' => 'ai_suggestion', 'status' => 'pending', 'generated_at' => now()->toIso8601String(), 'items' => $proposal],
            ]);
            AuditLog::record('psychometric.key_suggested', $test, reason: 'Usulan AI atas permintaan pengguna; belum diverifikasi HR.');
        });
    }

    public function assign(User $actor, int $testId, int $applicationId, string $opensAt, string $deadline): PsychometricAttempt
    {
        $this->admin($actor);
        Validator::make(['opens_at' => $opensAt, 'deadline' => $deadline], ['opens_at' => ['required', 'date'], 'deadline' => ['required', 'date', 'after:opens_at', 'after:now']])->validate();

        return DB::transaction(function () use ($actor, $testId, $applicationId, $opensAt, $deadline): PsychometricAttempt {
            $test = PsychometricTest::lockForUpdate()->findOrFail($testId);
            abort_unless($test->published_at, 422, 'Terbitkan tes sebelum menugaskan peserta.');
            $application = RecruitmentApplication::whereNull('archived_at')->whereNull('purged_at')->lockForUpdate()->findOrFail($applicationId);
            abort_unless($application->user->active && $application->user->role === Role::Candidate, 422);
            $attempt = PsychometricAttempt::firstOrCreate(['psychometric_test_id' => $testId, 'recruitment_application_id' => $applicationId], ['opens_at' => $opensAt, 'deadline' => $deadline, 'answers' => []]);
            if ($attempt->wasRecentlyCreated) {
                AuditLog::record('psychometric.assigned', $attempt, $actor);
            }

            return $attempt;
        });
    }

    public function assetPath(int $page, ?PsychometricTest $test = null): string
    {
        if ($page === 14) {
            return resource_path('psychometrics/cfit-b/tes-3-soal-10-final.png');
        }
        if ($page === 11 && $test?->corrected_page_path) {
            return Storage::disk('private')->path($test->corrected_page_path);
        }

        return resource_path('psychometrics/cfit-b/page-'.$page.'.jpg');
    }

    public function replaceImage(User $actor, int $id, int $section, int $question, string $part, UploadedFile $image): void
    {
        $this->admin($actor);
        Validator::make(['image' => $image], ['image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=10000,max_height=10000']])->validate();
        DB::transaction(function () use ($actor, $id, $section, $question, $part, $image): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($id);
            if ($test->published_at || $test->attempts()->exists()) {
                throw ValidationException::withMessages(['questionImage' => 'Gambar hanya dapat diganti pada draf yang belum ditugaskan.']);
            }
            $sections = $test->sections;
            $definition = $sections[$section] ?? null;
            if (! $definition || $question < 1 || $question > $definition['count'] || ! in_array($part, ['stem', ...array_slice(range('A', 'F'), 0, $definition['options'])], true)) {
                throw ValidationException::withMessages(['questionImage' => 'Pilih bagian, nomor soal, dan jenis gambar yang valid.']);
            }
            $old = $sections[$section]['images'][$question][$part] ?? null;
            $path = $image->store('psychometrics/questions', 'private');
            if (! $path) {
                throw ValidationException::withMessages(['questionImage' => 'Gambar gagal disimpan. Silakan coba lagi.']);
            }
            try {
                $sections[$section]['images'][$question][$part] = $path;
                $test->update(['sections' => $sections]);
                AuditLog::record('psychometric.image_replaced', $test, $actor, metadata: compact('section', 'question', 'part'));
            } catch (\Throwable $exception) {
                Storage::disk('private')->delete($path);
                throw $exception;
            }
            if ($old) {
                DB::afterCommit(fn () => Storage::disk('private')->delete($old));
            }
        });
    }

    public function deleteAttempt(User $actor, int $id): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $id): void {
            $attempt = PsychometricAttempt::lockForUpdate()->findOrFail($id);
            AuditLog::record('psychometric.attempt_deleted', $attempt, $actor, metadata: [
                'psychometric_test_id' => $attempt->psychometric_test_id,
                'recruitment_application_id' => $attempt->recruitment_application_id,
            ]);
            $attempt->delete();
        });
    }

    public function deletePackage(User $actor, int $id): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $id): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($id);
            if ($test->attempts()->exists()) {
                throw ValidationException::withMessages(['deletePackage' => 'Paket tidak dapat dihapus karena sudah memiliki penugasan atau hasil tes.']);
            }
            $paths = collect($test->sections)->flatMap(fn (array $section): array => collect($section['images'] ?? [])->flatten()->all())->all();
            if ($test->corrected_page_path) {
                $paths[] = $test->corrected_page_path;
            }
            AuditLog::record('psychometric.deleted', $test, $actor, metadata: ['title' => $test->title]);
            $test->delete();
            DB::afterCommit(fn () => Storage::disk('private')->delete($paths));
        });
    }

    /** @param array<int, string> $answer
     * @param  array<string, mixed>  $section
     */
    private function validateChoice(array $answer, array $section, bool $key = false): void
    {
        $allowed = array_slice(range('A', 'F'), 0, $section['options']);
        if (count($answer) > $section['choices'] || ($key && count($answer) !== $section['choices']) || count(array_unique($answer, SORT_REGULAR)) !== count($answer) || array_diff($answer, $allowed)) {
            throw ValidationException::withMessages(['answers' => 'Pilihan jawaban tidak sesuai aturan bagian tes.']);
        }
    }

    /** @param array<string, mixed> $payload */
    public function act(User $actor, int $id, string $action, array $payload = []): PsychometricAttempt
    {
        return DB::transaction(function () use ($actor, $id, $action, $payload): PsychometricAttempt {
            $attempt = PsychometricAttempt::lockForUpdate()->findOrFail($id);
            $this->authorize($actor, $attempt);
            $this->expire($attempt);
            if ($action === 'view' || $attempt->completed_at) {
                return $attempt;
            }
            abort_if(now()->lt($attempt->opens_at), 403, 'Jadwal tes belum dibuka.');
            $section = $attempt->test->sections[$attempt->section_index];
            abort_unless((int) ($payload['section'] ?? -1) === $attempt->section_index, 409, 'Bagian sudah berubah. Muat ulang halaman.');
            if ($action === 'start') {
                if (! $attempt->section_started_at) {
                    $expires = now()->addSeconds($section['seconds']);
                    $attempt->update(['section_started_at' => now(), 'section_expires_at' => $expires->min($attempt->deadline)]);
                    AuditLog::record('psychometric.section_started', $attempt, $actor, metadata: ['section' => $attempt->section_index]);
                }

                return $attempt;
            }
            abort_unless($attempt->section_started_at, 409, 'Bagian belum dimulai atau waktunya telah berakhir.');
            abort_unless((int) ($payload['revision'] ?? -1) === $attempt->revision, 409, 'Jawaban telah berubah di sesi lain. Muat ulang halaman.');
            Validator::make($payload, ['answers' => ['present', 'array', 'max:'.$section['count']], 'answers.*' => ['array'], 'answers.*.*' => ['string', 'in:A,B,C,D,E,F']])->validate();
            $answers = [];
            foreach ($payload['answers'] as $number => $answer) {
                abort_unless(ctype_digit((string) $number) && (int) $number >= 1 && (int) $number <= $section['count'], 422);
                $this->validateChoice($answer, $section);
                sort($answer);
                $answers[$number] = array_values($answer);
            }
            $all = $attempt->answers ?? [];
            $all[$attempt->section_index] = $answers;
            $attempt->update(['answers' => $all, 'revision' => $attempt->revision + 1]);
            if ($action === 'finish') {
                $this->advance($attempt);
            }

            return $attempt;
        });
    }

    public function expire(PsychometricAttempt $attempt): void
    {
        if ($attempt->completed_at) {
            return;
        }
        if (now()->gte($attempt->deadline)) {
            $this->complete($attempt);
        } elseif ($attempt->section_expires_at && now()->gte($attempt->section_expires_at)) {
            $this->advance($attempt);
        }
    }

    private function advance(PsychometricAttempt $attempt): void
    {
        $attempt->section_index++;
        $attempt->section_started_at = null;
        $attempt->section_expires_at = null;
        $attempt->revision++;
        $attempt->save();
        if ($attempt->section_index >= count($attempt->test->sections)) {
            $this->complete($attempt);
        }
    }

    private function complete(PsychometricAttempt $attempt): void
    {
        $scores = [];
        foreach ($attempt->test->answer_key as $section => $keys) {
            $scores[$section] = 0;
            foreach ($keys as $index => $key) {
                $answer = $attempt->answers[$section][$index + 1] ?? [];
                sort($answer);
                sort($key);
                if ($answer === $key) {
                    $scores[$section]++;
                }
            }
        }
        $attempt->update(['completed_at' => now(), 'raw_score' => array_sum($scores), 'section_scores' => $scores, 'section_started_at' => null, 'section_expires_at' => null]);
        AuditLog::record('psychometric.completed', $attempt);
    }
}
