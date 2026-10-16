<?php

namespace Database\Seeders;

use App\Models\TechnicalAssessmentAttempt;
use Illuminate\Database\Seeder;

class TechnicalAssessmentAttemptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        TechnicalAssessmentAttempt::factory()->create();
    }
}
