<?php

namespace App\Console\Commands;

use App\Models\TechnicalAssessmentAttempt;
use App\Services\TechnicalAssessmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinalizeTechnicalAssessments extends Command
{
    protected $signature = 'technical-assessments:finalize';

    protected $description = 'Mengunci tahapan asesmen teknis yang waktunya berakhir';

    public function handle(TechnicalAssessmentService $service): int
    {
        if (! TechnicalAssessmentService::available()) {
            return self::SUCCESS;
        }
        TechnicalAssessmentAttempt::whereNull('completed_at')->where(fn ($query) => $query->where('deadline', '<=', now())->orWhere('section_expires_at', '<=', now()))->chunkById(100, function ($attempts) use ($service): void {
            foreach ($attempts as $attempt) {
                DB::transaction(function () use ($attempt, $service): void {
                    $record = TechnicalAssessmentAttempt::lockForUpdate()->find($attempt->id);
                    if ($record) {
                        $service->expire($record);
                    }
                });
            }
        });

        return self::SUCCESS;
    }
}
