<?php

namespace App\Models;

use Database\Factories\EmailBroadcastDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailBroadcastDelivery extends Model
{
    /** @use HasFactory<EmailBroadcastDeliveryFactory> */
    use HasFactory;

    protected $fillable = ['email_broadcast_id', 'email', 'status', 'attempts', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(EmailBroadcast::class, 'email_broadcast_id');
    }
}
