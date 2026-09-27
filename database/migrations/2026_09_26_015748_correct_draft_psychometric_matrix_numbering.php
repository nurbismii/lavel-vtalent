<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tests = DB::table('psychometric_tests')->whereNull('published_at')->whereNull('corrected_page_path')->lockForUpdate()->get();
            foreach ($tests as $test) {
                $sections = json_decode($test->sections, true, 512, JSON_THROW_ON_ERROR);
                if (($sections[2]['count'] ?? null) !== 13 || ($sections[2]['pages'] ?? null) !== [10, 11]) {
                    continue;
                }
                $sections[2]['count'] = 12;
                $sections[2]['source_numbers'] = [1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13];
                DB::table('psychometric_tests')->where('id', $test->id)->update(['sections' => json_encode($sections, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        // Preserve corrected numbering: drafts may have been reviewed or published since migration.
    }
};
