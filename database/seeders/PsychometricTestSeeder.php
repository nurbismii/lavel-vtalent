<?php

namespace Database\Seeders;

use App\Models\PsychometricTest;
use App\Services\MbtiService;
use Illuminate\Database\Seeder;

class PsychometricTestSeeder extends Seeder
{
    public function run(): void
    {
        PsychometricTest::firstOrCreate(['title' => 'TES IQ', 'type' => 'iq'], [
            'sections' => array_map(fn (array $section): array => [...$section, 'seconds' => null], config('psychometrics.sections')),
            'answer_key' => [],
        ]);
        PsychometricTest::firstOrCreate(['title' => 'TES MBTI (7 pilihan)', 'type' => 'mbti'], [
            'sections' => app(MbtiService::class)->sections(),
            'answer_key' => [],
        ]);
    }
}
