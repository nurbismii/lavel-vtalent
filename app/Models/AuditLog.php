<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, Model $target, ?User $actor = null, ?string $reason = null, array $metadata = []): self
    {
        return self::create(['actor_id' => $actor?->id, 'action' => $action, 'target_type' => $target->getMorphClass(), 'target_id' => $target->getKey(), 'reason' => $reason, 'metadata' => $metadata]);
    }
}
