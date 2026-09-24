<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormResponseRevision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'document_ids' => 'array', 'submitted_at' => 'datetime'];
    }
}
