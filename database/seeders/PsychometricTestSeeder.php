<?php

namespace Database\Seeders;

use App\Models\PsychometricTest;
use Illuminate\Database\Seeder;

class PsychometricTestSeeder extends Seeder
{
    public function run(): void
    {
        PsychometricTest::firstOrCreate(['title' => 'CFIT Skala 3 Bentuk B · 2021'], [
            'sections' => array_map(fn (array $section): array => [...$section, 'seconds' => null], config('psychometrics.sections')),
            'answer_key' => [],
        ]);
    }
}
