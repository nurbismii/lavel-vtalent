<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentApplication extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'position_id', 'recruitment_period_id', 'active_user_id', 'archived_at', 'purged_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPeriod::class, 'recruitment_period_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }
}
