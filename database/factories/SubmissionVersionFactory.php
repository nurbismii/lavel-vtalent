<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubmissionVersionFactory extends Factory
{
    protected $model = SubmissionVersion::class;

    public function definition(): array
    {
        return ['submission_id' => Submission::factory(), 'number' => 1, 'status' => 'draft', 'links' => []];
    }
}
