<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsychometricTest extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'corrected_page_path', 'sections', 'answer_key', 'answer_key_review', 'published_at'];

    protected $hidden = ['answer_key', 'answer_key_review'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'answer_key' => 'array', 'answer_key_review' => 'array', 'published_at' => 'datetime'];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PsychometricAttempt::class);
    }
}
