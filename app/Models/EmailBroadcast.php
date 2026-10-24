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

    protected $fillable = ['created_by', 'submission_key', 'subject', 'body', 'attachment'];

    protected function casts(): array
    {
        return ['attachment' => 'array'];
    }

    /** @return list<array{path: string, name: string, mime: string}> */
    public function attachmentFiles(): array
    {
        $attachments = $this->attachment ?? [];

        return isset($attachments['path']) ? [$attachments] : $attachments;
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailBroadcastDelivery::class);
    }
}
