<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateFormVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(CandidateForm::class, 'candidate_form_id');
    }
}
