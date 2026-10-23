<?php

namespace App\Models;

use Database\Factories\EmailBroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailBroadcast extends Model
{
    /** @use HasFactory<EmailBroadcastFactory> */
    use HasFactory;

    protected $fillable = ['created_by', 'submission_key', 'subject', 'body'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailBroadcastDelivery::class);
    }
}
