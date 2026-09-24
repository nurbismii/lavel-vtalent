<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormDocument extends Model
{
    protected $attributes = ['selected' => true, 'scan_status' => 'pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['selected' => 'boolean'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function available(): bool
    {
        return $this->scan_status === 'clean' || (! config('submissions.scan_enabled') && $this->scan_status === 'skipped');
    }
}
