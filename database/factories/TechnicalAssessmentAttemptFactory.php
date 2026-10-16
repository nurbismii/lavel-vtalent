<?php

namespace Database\Factories;

use App\Models\RecruitmentApplication;
use App\Models\TechnicalAssessmentAttempt;
use App\Services\TechnicalAssessmentService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicalAssessmentAttempt>
 */
class TechnicalAssessmentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $package = app(TechnicalAssessmentService::class)->packages()['A'];

        return ['recruitment_application_id' => RecruitmentApplication::factory(), 'form' => 'A', 'version' => $package['version'], 'package' => $package, 'opens_at' => now()->subMinute(), 'deadline' => now()->addHours(2), 'answers' => []];
    }
}
