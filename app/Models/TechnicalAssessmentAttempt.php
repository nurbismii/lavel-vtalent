<?php

namespace App\Models;

use Database\Factories\TechnicalAssessmentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalAssessmentAttempt extends Model
{
    /** @use HasFactory<TechnicalAssessmentAttemptFactory> */
    use HasFactory;

    protected $fillable = ['recruitment_application_id', 'form', 'version', 'package', 'opens_at', 'deadline', 'section_index', 'section_started_at', 'section_expires_at', 'consented_at', 'answers', 'conditions', 'revision', 'completed_at', 'scores', 'review'];

    protected $hidden = ['package', 'answers', 'scores', 'review', 'conditions'];

    protected function casts(): array
    {
        return ['package' => 'array', 'answers' => 'array', 'conditions' => 'array', 'scores' => 'array', 'review' => 'array', 'opens_at' => 'datetime', 'deadline' => 'datetime', 'section_started_at' => 'datetime', 'section_expires_at' => 'datetime', 'consented_at' => 'datetime', 'completed_at' => 'datetime', 'revision' => 'integer', 'section_index' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'target_id')->where('target_type', $this->getMorphClass())->where('action', 'like', 'technical_assessment.%');
    }
}
