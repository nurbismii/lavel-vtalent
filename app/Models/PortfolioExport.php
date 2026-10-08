<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

class PortfolioExport extends Model
{
    use Prunable;

    protected $fillable = ['actor_id', 'application_ids', 'status', 'path', 'error', 'candidate_count', 'expires_at'];

    protected function casts(): array
    {
        return ['application_ids' => 'array', 'expires_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }

    protected function pruning(): void
    {
        Storage::disk('private')->delete([$this->path, $this->path.'.xlsx']);
    }
}
