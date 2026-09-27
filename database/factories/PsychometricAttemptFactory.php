<?php

namespace Database\Factories;

use App\Models\PsychometricTest;
use App\Models\RecruitmentApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsychometricAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['psychometric_test_id' => PsychometricTest::factory()->published(), 'recruitment_application_id' => RecruitmentApplication::factory(), 'opens_at' => now()->subMinute(), 'deadline' => now()->addDay(), 'answers' => []];
    }
}
