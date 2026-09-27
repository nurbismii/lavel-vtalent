<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PsychometricTestFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'sections' => config('psychometrics.sections'), 'answer_key' => []];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now(),
            'sections' => array_map(fn (array $s): array => [...$s, 'seconds' => 60], config('psychometrics.sections')),
            'answer_key' => array_map(fn (array $s): array => array_fill(0, $s['count'], $s['choices'] === 2 ? ['A', 'B'] : ['A']), config('psychometrics.sections')),
        ]);
    }
}
