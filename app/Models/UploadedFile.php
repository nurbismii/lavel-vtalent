<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadedFile extends Model
{
    use HasFactory;

    protected $fillable = ['recruitment_application_id', 'submission_id', 'uploader_id', 'purpose', 'disk', 'path', 'original_name', 'mime', 'size', 'checksum', 'scan_status', 'scan_message'];

    protected function casts(): array
    {
        return ['scan_status' => ScanStatus::class, 'size' => 'integer'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SubmissionAttachment::class);
    }
}
