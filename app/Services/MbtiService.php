<?php

namespace App\Services;

class MbtiService
{
    /**
     * @param  array{type: ?string, dimensions: array<string, array<string, mixed>>, answer_format?: string}  $result
     * @return array<string, mixed>|null
     */
    public function report(array $result): ?array
    {
        $type = $result['type'];
        if (! $type) {
            return null;
        }
        $balanced = str_contains($type, 'X');
        $report = $balanced ? config('mbti_reports.balanced') : (config('mbti_reports.types')[$type] ?? null);
        if (! $report) {
            return null;
        }
        $weighted = ($result['answer_format'] ?? null) === 'likert7';
        $report['scores'] = [
            'Persentase dihitung dari jumlah jawaban untuk satu sisi dibagi jumlah jawaban yang tersimpan dalam pasangan yang sama, lalu dikalikan 100 dan dibulatkan. Contoh: 7 dari 10 jawaban mendukung E berarti E 70% dan I 30%. Nilai ini bukan persentil terhadap peserta lain, nilai kelulusan, atau persentase kemampuan.',
            'Baca pasangan satu per satu sebelum membaca gambaran tipe. Selisih jawaban menunjukkan perbandingan pilihan pada saat mengisi kuesioner internal ini; selisih tersebut bukan ukuran ilmiah tentang kuatnya sifat, tingkat keterampilan, atau kepastian tipe. Pengalaman, kebiasaan menjawab, dan konteks kegiatan perlu dibahas melalui contoh nyata.',
        ];
        if ($weighted) {
            $report['scores'] = [
                'Setiap pernyataan memiliki tujuh tingkat persetujuan. Sangat setuju memberi 6 poin pada sisi yang didukung pernyataan dan 0 pada sisi lawannya; setuju 5:1; agak setuju 4:2; netral 3:3; agak tidak setuju 2:4; tidak setuju 1:5; sangat tidak setuju 0:6. Arah pernyataan berbeda-beda, sehingga setuju tidak selalu mendukung huruf yang sama.',
                'Persentase adalah poin satu sisi dibagi total poin pasangan, lalu dikalikan 100 dan dibulatkan; sisi lawan melengkapi hingga 100%. Contoh: 36 dari 60 poin berarti 60%, bukan 6 jawaban dari 10. Jawaban netral tetap dihitung sebagai dijawab dan memberi poin sama pada kedua sisi. Persentase bukan persentil, ukuran kemampuan, nilai kelulusan, atau kepastian tipe. Bandingkan uraian dengan pengalaman nyata.',
            ];
        }
        foreach ($result['dimensions'] as $pair => $dimension) {
            $preference = $dimension['preference'];
            $label = $preference ?? implode(' / ', str_split($pair));
            if ($balanced) {
                $description = config('mbti_reports.preferences')[$preference ?? $pair];
                foreach (['traits', 'strengths', 'challenges', 'growth'] as $section) {
                    $report[$section][] = $label.' · '.$description[$section];
                }
            }
            [$left, $right] = str_split($pair);
            $scores = $dimension['scores'];
            $percentages = $dimension['percentages'];
            $difference = abs($scores[$left] - $scores[$right]);
            $unit = $weighted ? 'poin' : 'jawaban';
            $denominator = $weighted ? array_sum($scores) : $dimension['answered'];
            $reading = $left.'/'.$right.': '.$left.' '.$scores[$left].' dari '.$denominator.' '.$unit.' ('.$percentages[$left].'%) dan '.$right.' '.$scores[$right].' dari '.$denominator.' '.$unit.' ('.$percentages[$right].'%). Selisih '.$difference.' '.$unit.'. ';
            if ($weighted) {
                $reading .= 'Pilihan netral: '.$dimension['neutral'].' dari '.$dimension['answered'].' jawaban. ';
            }
            if ($preference === null) {
                $reading .= ($weighted ? 'Jumlah poin' : 'Jumlah pilihan').' sama, sehingga pasangan ini ditandai X. Gunakan kedua pendekatan sebagai bahan pengamatan; skor sama tidak membuktikan kedua cara sudah sama terampilnya atau selalu nyaman dalam setiap keadaan.';
            } else {
                $opposite = $preference === $left ? $right : $left;
                $reading .= 'Kode '.$preference.' berasal dari jumlah '.($weighted ? 'poin' : 'pilihan').' yang lebih banyak pada sisi tersebut. ';
                if ($difference === 2) {
                    $reading .= ($weighted ? 'Perubahan satu tingkat jawaban ke arah '.$opposite.' pada satu pernyataan dapat membuat poin kedua sisi sama.' : 'Jika satu jawaban '.$preference.' berubah ke '.$opposite.', jumlah kedua sisi menjadi sama.').' Karena itu, cocokkan uraian tipe dengan pengalaman pada kedua sisi sebelum menganggapnya sebagai gambaran diri yang menetap.';
                    $report['growth'][] = $pair.' · Selisih pada pasangan ini dapat menjadi seimbang dengan perubahan satu '.($weighted ? 'tingkat jawaban' : 'jawaban').'. Catat dua situasi ketika Anda menggunakan cara '.$preference.' dan dua situasi ketika menggunakan cara '.$opposite.'; bandingkan kebutuhan yang mendasarinya.';
                } elseif ($scores[$opposite] === 0) {
                    $reading .= 'Semua jawaban tersimpan pada pasangan ini mendukung '.$preference.'. Ini tidak berarti Anda tidak mampu memakai pendekatan '.$opposite.' atau harus selalu bertindak sesuai satu sisi.';
                } else {
                    $reading .= 'Perbandingan ini menjelaskan pola jawaban, bukan tingkat kemampuan. Tetap periksa kapan pendekatan '.$opposite.' membantu dan bagian uraian mana yang tidak sesuai dengan pengalaman Anda.';
                }
            }
            $report['scores'][] = $reading;
        }

        return $report;
    }

    /** @return array<int, array<string, mixed>> */
    public function sections(): array
    {
        $questions = array_map(fn (array $question): array => [
            'dimension' => $question['dimension'],
            'preference' => $question['preferences']['A'],
            'statement' => $question['options']['A'],
            'options' => config('mbti.scale'),
        ], config('mbti.questions'));

        return [[
            'title' => 'Preferensi kepribadian MBTI',
            'count' => count($questions),
            'options' => 7,
            'answer_format' => 'likert7',
            'weights' => ['A' => 6, 'B' => 5, 'C' => 4, 'D' => 3, 'E' => 2, 'F' => 1, 'G' => 0],
            'choices' => 1,
            'seconds' => 1800,
            'questions' => $questions,
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, array<int, array<int, string>>>  $answers
     * @return array{type: ?string, answered: int, total: int, dimensions: array<string, array<string, mixed>>, answer_format?: string}
     */
    public function score(array $sections, array $answers): array
    {
        $weighted = ($sections[0]['answer_format'] ?? null) === 'likert7';
        $dimensions = [];
        foreach (config('mbti.dimensions') as $pair => $labels) {
            $dimensions[$pair] = ['scores' => array_fill_keys(array_keys($labels), 0), 'answered' => 0, 'total' => 0];
        }
        foreach ($sections as $index => $section) {
            foreach ($section['questions'] as $number => $question) {
                $dimension = &$dimensions[$question['dimension']];
                $dimension['total']++;
                $answer = $answers[$index][$number + 1] ?? [];
                if ($weighted) {
                    $dimension['neutral'] ??= 0;
                    if (count($answer) === 1 && isset($section['weights'][$answer[0]])) {
                        $weight = $section['weights'][$answer[0]];
                        $agree = $question['preference'];
                        $opposite = str_replace($agree, '', $question['dimension']);
                        $dimension['scores'][$agree] += $weight;
                        $dimension['scores'][$opposite] += 6 - $weight;
                        $dimension['answered']++;
                        $dimension['neutral'] += $weight === 3 ? 1 : 0;
                    }
                } elseif (count($answer) === 1 && isset($question['preferences'][$answer[0]])) {
                    $dimension['scores'][$question['preferences'][$answer[0]]]++;
                    $dimension['answered']++;
                }
                unset($dimension);
            }
        }
        $type = '';
        foreach ($dimensions as $pair => &$dimension) {
            [$left, $right] = str_split($pair);
            $dimension['preference'] = $dimension['scores'][$left] === $dimension['scores'][$right]
                ? null : ($dimension['scores'][$left] > $dimension['scores'][$right] ? $left : $right);
            $points = array_sum($dimension['scores']);
            $leftPercent = $points ? (int) round($dimension['scores'][$left] / $points * 100) : null;
            $dimension['percentages'] = [$left => $leftPercent, $right => $leftPercent === null ? null : 100 - $leftPercent];
            $type .= $dimension['preference'] ?? 'X';
        }
        unset($dimension);
        $answered = array_sum(array_column($dimensions, 'answered'));
        $total = array_sum(array_column($dimensions, 'total'));

        $result = ['type' => $answered === $total ? $type : null, 'answered' => $answered, 'total' => $total, 'dimensions' => $dimensions];
        if ($weighted) {
            $result['answer_format'] = 'likert7';
        }

        return $result;
    }
}
