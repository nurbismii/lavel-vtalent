<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormAccountDispatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['application_data' => 'array', 'linked_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AccessDelivery::class, 'access_delivery_id');
    }

    public function deliveryStatus(): string
    {
        return $this->delivery?->status ?? $this->status;
    }
}
