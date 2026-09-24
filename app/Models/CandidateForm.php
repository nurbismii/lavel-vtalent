<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateForm extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'archived_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CandidateFormVersion::class);
    }
}
