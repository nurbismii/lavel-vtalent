<?php

namespace App\Console\Commands;

use App\Models\PsychometricAttempt;
use App\Services\PsychometricService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinalizePsychometrics extends Command
{
    protected $signature = 'psychometrics:finalize';

    protected $description = 'Lock expired psychometric sections and score completed attempts';

    public function handle(PsychometricService $service): int
    {
        if (! PsychometricService::available()) {
            return self::SUCCESS;
        }
        PsychometricAttempt::whereNull('completed_at')->where(fn ($q) => $q->where('deadline', '<=', now())->orWhere('section_expires_at', '<=', now()))->chunkById(100, function ($attempts) use ($service): void {
            foreach ($attempts as $attempt) {
                DB::transaction(function () use ($attempt, $service): void {
                    $record = PsychometricAttempt::lockForUpdate()->find($attempt->id);
                    if ($record) {
                        $service->expire($record);
                    }
                });
            }
        });

        return self::SUCCESS;
    }
}
