<?php

namespace Database\Seeders;

use App\Models\PsychometricTest;
use Illuminate\Database\Seeder;

class PsychometricTestSeeder extends Seeder
{
    public function run(): void
    {
        PsychometricTest::firstOrCreate(['title' => 'TES IQ'], [
            'sections' => array_map(fn (array $section): array => [...$section, 'seconds' => null], config('psychometrics.sections')),
            'answer_key' => [],
        ]);
    }
}
