<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecruitmentPeriod extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'starts_at', 'ends_at', 'active'];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'active' => 'boolean'];
    }
}
