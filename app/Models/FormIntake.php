<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormIntake extends Model
{
    protected $attributes = ['active' => true];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_at' => 'datetime', 'deadline' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CandidateFormVersion::class, 'candidate_form_version_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPeriod::class, 'recruitment_period_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    public function open(): bool
    {
        return $this->active && ! $this->version->form->archived_at && $this->position->active && $this->period->active
            && (! $this->starts_at || $this->starts_at->lte(now())) && (! $this->deadline || $this->deadline->gt(now()));
    }
}
