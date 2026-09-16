<?php

namespace App\Enums;

enum SubmissionType: string
{
    case Portfolio = 'portfolio';
    case TechnicalTest = 'technical_test';

    public function label(): string
    {
        return match ($this) {
            self::Portfolio => 'Portofolio',
            self::TechnicalTest => 'Hasil Tes Teknis',
        };
    }
}
