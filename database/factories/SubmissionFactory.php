<?php

namespace Database\Factories;

use App\Models\RecruitmentApplication;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        return ['recruitment_application_id' => RecruitmentApplication::factory(), 'type' => 'portfolio', 'status' => 'not_started', 'deadline' => now()->addDays(3)];
    }
}
