<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsychometricAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['psychometric_test_id', 'recruitment_application_id', 'opens_at', 'deadline', 'section_index', 'section_started_at', 'section_expires_at', 'answers', 'revision', 'completed_at', 'raw_score', 'section_scores'];

    protected $hidden = ['raw_score', 'section_scores'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'deadline' => 'datetime', 'section_started_at' => 'datetime', 'section_expires_at' => 'datetime', 'completed_at' => 'datetime', 'answers' => 'array', 'section_scores' => 'array', 'section_index' => 'integer', 'revision' => 'integer', 'raw_score' => 'integer'];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(PsychometricTest::class, 'psychometric_test_id');
    }

    public function iqScore(): ?int
    {
        if (! $this->completed_at || $this->raw_score === null) {
            return null;
        }

        return config('psychometrics.iq_by_raw_score')[$this->raw_score] ?? null;
    }

    public function iqCategory(): ?string
    {
        $iq = $this->iqScore();
        if ($iq === null) {
            return null;
        }

        foreach (config('psychometrics.iq_categories') as $category) {
            if ($category['maximum'] === null || $iq <= $category['maximum']) {
                return $category['label'];
            }
        }

        return null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }
}
