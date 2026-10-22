<?php

namespace App\Models;

use Database\Factories\TechnicalAssessmentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TechnicalAssessmentAttempt extends Model
{
    /** @use HasFactory<TechnicalAssessmentAttemptFactory> */
    use HasFactory;

    protected $fillable = ['recruitment_application_id', 'form', 'version', 'package', 'opens_at', 'deadline', 'section_index', 'section_started_at', 'section_expires_at', 'consented_at', 'reasoning_answers_locked_at', 'simulation_answers_locked_at', 'answers', 'conditions', 'revision', 'completed_at', 'scores', 'review'];

    protected $hidden = ['package', 'answers', 'scores', 'review', 'conditions'];

    protected function casts(): array
    {
        return ['package' => 'array', 'answers' => 'array', 'conditions' => 'array', 'scores' => 'array', 'review' => 'array', 'opens_at' => 'datetime', 'deadline' => 'datetime', 'section_started_at' => 'datetime', 'section_expires_at' => 'datetime', 'consented_at' => 'datetime', 'reasoning_answers_locked_at' => 'datetime', 'simulation_answers_locked_at' => 'datetime', 'completed_at' => 'datetime', 'revision' => 'integer', 'section_index' => 'integer'];
    }

    public function isReasoningSection(): bool
    {
        return $this->form === 'B' && in_array('process', array_column($this->package['sections'][$this->section_index]['questions'] ?? [], 'id'), true);
    }

    public function isSimulationSection(): bool
    {
        return $this->form === 'B' && ($this->package['sections'][$this->section_index]['kind'] ?? null) === 'dynamic_simulation';
    }

    /** @param array<string, mixed> $section */
    public function sectionTitle(array $section): string
    {
        if ($this->form !== 'B' || ! in_array($section['title'], ['Penalaran paralel dan penjelasan proses', 'Penalaran paralel', 'Simulasi dinamis', 'Simulasi dinamis · keputusan awal', 'Simulasi dinamis · fakta baru', 'Bagian 3. Probe Konsistensi', 'Probe konsistensi', 'Kuesioner', 'Catatan kondisi dan konfirmasi'], true)) {
            return $section['title'];
        }
        if (($section['kind'] ?? null) === 'questionnaire') {
            return 'Catatan kondisi dan konfirmasi';
        }
        if (($section['kind'] ?? null) === 'dynamic_simulation') {
            return 'Simulasi dinamis';
        }

        return match ($section['questions'][0]['id'] ?? null) {
            'B01' => 'Penalaran paralel',
            'probe_1' => 'Probe konsistensi',
            default => $section['title'],
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function sectionsForDisplay(): array
    {
        return collect($this->package['sections'])
            ->reject(fn (array $section): bool => $this->form === 'B' && ($section['kind'] ?? null) === 'questionnaire')
            ->map(function (array $section): array {
                $section['title'] = $this->sectionTitle($section);

                return $section;
            })->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function conditionQuestions(): array
    {
        return ($this->package['condition_questions'] ?? []) ?: (collect($this->package['sections'])->firstWhere('kind', 'questionnaire')['questions'] ?? []);
    }

    /** @return array<string, string|null> */
    public function conditionAnswers(): array
    {
        $index = collect($this->package['sections'])->search(fn (array $section): bool => ($section['kind'] ?? null) === 'questionnaire');

        return $this->conditions ?? ($index !== false ? ($this->answers[$index] ?? []) : []);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'target_id')->where('target_type', $this->getMorphClass())->where('action', 'like', 'technical_assessment.%');
    }

    public function completedFormA(): HasOne
    {
        return $this->hasOne(self::class, 'recruitment_application_id', 'recruitment_application_id')->where('form', 'A')->whereNotNull('completed_at');
    }

    public function isWaitingForFormA(): bool
    {
        return $this->form !== 'A' && ! $this->completed_at && ! $this->section_started_at
            && ! ($this->completed_form_a_exists ?? $this->completedFormA()->exists());
    }
}
