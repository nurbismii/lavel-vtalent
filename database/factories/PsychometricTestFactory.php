<?php

namespace Database\Factories;

use App\Services\MbtiService;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsychometricTestFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'sections' => config('psychometrics.sections'), 'answer_key' => []];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now(),
            'sections' => ($attributes['type'] ?? 'iq') === 'mbti' ? $attributes['sections'] : array_map(fn (array $s): array => [...$s, 'seconds' => 60], config('psychometrics.sections')),
            'answer_key' => ($attributes['type'] ?? 'iq') === 'mbti' ? [] : array_map(fn (array $s): array => array_fill(0, $s['count'], $s['choices'] === 2 ? ['A', 'B'] : ['A']), config('psychometrics.sections')),
        ]);
    }

    public function mbti(): static
    {
        return $this->state(fn (): array => [
            'title' => 'TES MBTI (7 pilihan)',
            'type' => 'mbti',
            'sections' => app(MbtiService::class)->sections(),
            'answer_key' => [],
        ]);
    }

    public function legacyMbti(): static
    {
        return $this->mbti()->state(fn (): array => [
            'title' => 'TES MBTI',
            'sections' => [[
                'title' => 'Preferensi kepribadian MBTI', 'count' => 40, 'options' => 2,
                'choices' => 1, 'seconds' => 1800, 'questions' => config('mbti.questions'),
            ]],
        ]);
    }
}
