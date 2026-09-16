<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = ['recruitment_application_id', 'type', 'status', 'deadline', 'task_label', 'instructions', 'administrative_reason', 'current_version_id'];

    protected function casts(): array
    {
        return ['type' => SubmissionType::class, 'status' => SubmissionStatus::class, 'deadline' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class, 'current_version_id');
    }

    public function editable(): bool
    {
        return ! $this->application->archived_at && ! $this->lockedUntilPortfolioSubmitted() && now()->lte($this->deadline) && in_array($this->status, [SubmissionStatus::NotStarted, SubmissionStatus::Draft, SubmissionStatus::Revision], true);
    }

    public function lockedUntilPortfolioSubmitted(): bool
    {
        if ($this->type !== SubmissionType::TechnicalTest) {
            return false;
        }

        $portfolio = $this->application->submissions()->where('type', SubmissionType::Portfolio)->first();

        return ! in_array($portfolio?->status, [SubmissionStatus::Submitted, SubmissionStatus::Exempt], true);
    }

    public function localDeadline(): string
    {
        return $this->deadline->timezone(AppSetting::valueFor('timezone'))->format('d M Y, H:i T');
    }
}
