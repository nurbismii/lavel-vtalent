<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function available(): bool
    {
        return $this === self::Clean
            || (! config('submissions.scan_enabled') && in_array($this, [self::Skipped, self::Pending, self::Failed], true));
    }

    public function label(): string
    {
        if ($this !== self::Clean && $this->available()) {
            return 'Tersimpan';
        }

        return match ($this) {
            self::Pending => 'Menunggu pemeriksaan keamanan',
            self::Clean => 'Siap dikumpulkan',
            self::Rejected => 'File ditolak',
            self::Failed => 'Pemeriksaan gagal',
            self::Skipped => 'Belum dipindai antivirus',
        };
    }
}
