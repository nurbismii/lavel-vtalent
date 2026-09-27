<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tests = DB::table('psychometric_tests')->whereNull('published_at')->lockForUpdate()->get();
            foreach ($tests as $test) {
                if (DB::table('psychometric_attempts')->where('psychometric_test_id', $test->id)->exists()) {
                    continue;
                }
                $sections = json_decode($test->sections, true, 512, JSON_THROW_ON_ERROR);
                if (($sections[2]['count'] ?? null) !== 12 || ($sections[2]['source_numbers'] ?? null) !== [1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13]) {
                    continue;
                }
                $keys = json_decode($test->answer_key ?? '[]', true, 512, JSON_THROW_ON_ERROR);
                if (count($keys[2] ?? []) > 12) {
                    continue;
                }
                $sections[2]['count'] = 13;
                $sections[2]['source_numbers'] = range(1, 13);
                $sections[2]['pages'] = [10, 14, 11];
                $images = [];
                foreach ($sections[2]['images'] ?? [] as $number => $parts) {
                    $images[$number >= 10 ? $number + 1 : $number] = $parts;
                }
                $sections[2]['images'] = $images;
                if (! empty($keys[2])) {
                    $keys[2] = array_pad($keys[2], 12, []);
                    array_splice($keys[2], 9, 0, [[]]);
                }
                $review = json_decode($test->answer_key_review ?? 'null', true, 512, JSON_THROW_ON_ERROR);
                if ($review) {
                    $review['status'] = 'pending';
                    if (isset($review['items'][2])) {
                        array_splice($review['items'][2], 9, 0, [['answer' => [], 'confidence' => 'belum ditetapkan', 'reason' => 'Soal nomor 10 dipulihkan; kunci perlu diisi HR.']]);
                    }
                }
                DB::table('psychometric_tests')->where('id', $test->id)->update([
                    'sections' => json_encode($sections, JSON_THROW_ON_ERROR),
                    'answer_key' => json_encode($keys, JSON_THROW_ON_ERROR),
                    'answer_key_review' => $review ? json_encode($review, JSON_THROW_ON_ERROR) : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve numbering and reviewed answers after a restored package has been published.
    }
};
