<?php

namespace Database\Factories;

use App\Models\RecruitmentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecruitmentPeriodFactory extends Factory
{
    protected $model = RecruitmentPeriod::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString(), 'active' => true];
    }
}
