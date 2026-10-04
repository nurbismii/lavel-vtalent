<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class PsychometricResultExportService
{
    /** @param array{search: string, position: string, period: string} $filters
     * @return Builder<PsychometricAttempt>
     */
    public function filtered(array $filters): Builder
    {
        return PsychometricAttempt::query()
            ->when($filters['position'] !== '', fn ($query) => $query->whereHas('application', fn ($application) => $application->where('position_id', $filters['position'])))
            ->when($filters['period'] !== '', fn ($query) => $query->whereHas('application', fn ($application) => $application->where('recruitment_period_id', $filters['period'])))
            ->when(trim($filters['search']) !== '', fn ($query) => $query->whereHas('application.user', fn ($user) => $user->where(fn ($candidate) => $candidate
                ->where('name', 'like', '%'.trim($filters['search']).'%')
                ->orWhere('email', 'like', '%'.trim($filters['search']).'%'))));
    }

    /** @param array{search: string, position: string, period: string} $filters */
    public function write(User $actor, array $filters, string $path): void
    {
        abort_unless(PsychometricService::available(), 404);
        app(PsychometricService::class)->admin($actor);
        $query = $this->filtered($filters)->whereNotNull('completed_at');
        $options = new Options;
        $options->DEFAULT_COLUMN_WIDTH = 26;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('415E9E')->setShouldWrapText();
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        $date = (new Style)->setFormat('dd/mm/yyyy hh:mm');
        $timezone = AppSetting::valueFor('timezone');
        $count = 0;
        $sheetCount = 0;
        try {
            $tests = PsychometricTest::whereIn('id', (clone $query)->select('psychometric_test_id'))->orderBy('id')->get();
            foreach ($tests as $test) {
                $sheet = $sheetCount++ ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
                $sheet->setName('Hasil tes #'.$test->id);
                $sheet->setSheetView((new SheetView)->setFreezeRow(2));
                $headers = ['ID hasil', 'ID lamaran', 'Nama kandidat', 'Email', 'Posisi', 'Periode rekrutmen', 'Paket tes', 'Status'];
                foreach ($test->sections as $section) {
                    $headers[] = $section['title'].' (maks. '.$section['count'].')';
                }
                $headers = [...$headers, 'Skor mentah total', 'Skor maksimum', 'IQ (konversi aplikasi)', 'Kategori IQ', 'Selesai ('.$timezone.')', 'Tenggat ('.$timezone.')'];
                $writer->addRow(new Row(array_map(fn (string $value) => new StringCell($value, $header), $headers)));
                $attempts = (clone $query)->where('psychometric_test_id', $test->id)
                    ->with(['test', 'application.user', 'application.position', 'application.period'])->lazyById(200);
                foreach ($attempts as $attempt) {
                    $application = $attempt->application;
                    $cells = [Cell::fromValue($attempt->id), Cell::fromValue($application->id)];
                    foreach ([$application->user->name, $application->user->email, $application->position->name, $application->period->name, $test->title, 'Selesai'] as $value) {
                        $cells[] = new StringCell($value, $text);
                    }
                    foreach ($test->sections as $index => $section) {
                        $cells[] = Cell::fromValue($attempt->section_scores[$index] ?? null);
                    }
                    $cells[] = Cell::fromValue($attempt->raw_score);
                    $cells[] = Cell::fromValue(array_sum(array_column($test->sections, 'count')));
                    $cells[] = Cell::fromValue($attempt->iqScore());
                    $cells[] = new StringCell($attempt->iqCategory() ?? 'Konversi tidak tersedia', $text);
                    $cells[] = Cell::fromValue($attempt->completed_at->copy()->timezone($timezone), $date);
                    $cells[] = Cell::fromValue($attempt->deadline->copy()->timezone($timezone), $date);
                    $writer->addRow(new Row($cells));
                    $count++;
                }
                $sheet->setAutoFilter(new AutoFilter(0, 1, count($headers) - 1, $sheet->getWrittenRowCount()));
            }
            if (! $sheetCount) {
                $writer->getCurrentSheet()->setName('Hasil psikotes');
                $writer->addRow(Row::fromValues(['Tidak ada hasil tes selesai sesuai filter.']));
            }
        } finally {
            $writer->close();
        }
        AuditLog::record('psychometric.results_exported', $actor, $actor, metadata: ['count' => $count, 'filters' => $filters]);
    }
}
