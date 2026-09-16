<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case NotStarted = 'not_started';
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Revision = 'revision';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Mulai',
            self::Draft => 'Draf',
            self::Submitted => 'Sudah dikumpulkan',
            self::Revision => 'Dibuka untuk revisi',
            self::Exempt => 'Tidak diwajibkan',
        };
    }
}
