<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FormResponse extends Model
{
    protected $attributes = ['status' => 'draft', 'lock_version' => 0, 'access_generation' => 1, 'activation_pending' => false, 'name' => ''];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'email_verified_at' => 'datetime', 'submitted_at' => 'datetime', 'revision_deadline' => 'datetime', 'linked_at' => 'datetime', 'activation_pending' => 'boolean', 'access_generation' => 'integer', 'lock_version' => 'integer'];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(FormIntake::class, 'form_intake_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FormDocument::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FormResponseRevision::class);
    }

    public function latestRevision(): HasOne
    {
        return $this->hasOne(FormResponseRevision::class)->ofMany('number', 'max');
    }

    public function accountDispatch(): HasOne
    {
        return $this->hasOne(FormAccountDispatch::class);
    }

    public function editable(): bool
    {
        if ($this->status === 'submitted' || $this->application?->archived_at || $this->application?->purged_at) {
            return false;
        }
        if ($this->status === 'revision') {
            return $this->intake->active && ! $this->intake->version->form->archived_at
                && $this->intake->position->active && $this->intake->period->active && $this->revision_deadline?->gt(now());
        }

        return $this->intake->open();
    }
}
