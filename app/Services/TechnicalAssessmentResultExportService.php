<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\User;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class TechnicalAssessmentResultExportService
{
    public function write(User $actor, string $search, string $path, ?int $applicationId = null): void
    {
        abort_unless(TechnicalAssessmentService::available(), 404);
        app(TechnicalAssessmentService::class)->admin($actor);
        $query = TechnicalAssessmentAttempt::whereNotNull('completed_at')->whereIn('form', ['A', 'B'])
            ->whereHas('application', fn ($application) => $application->whereNull('purged_at')
                ->whereHas('user', fn ($user) => $user->where(fn ($candidate) => $candidate
                    ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))))
            ->when($applicationId !== null, fn ($attempt) => $attempt->where('recruitment_application_id', $applicationId));
        $options = new Options;
        $options->DEFAULT_COLUMN_WIDTH = 24;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('415E9E')->setShouldWrapText();
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        $timezone = AppSetting::valueFor('timezone');
        $count = 0;

        try {
            foreach (['A', 'B'] as $form) {
                $scoreLabels = $form === 'A'
                    ? ['Verbal', 'Numerik', 'Logis', 'Penalaran', 'Dokumen', 'SJT', 'IPP pilot', 'Perlu verifikasi', 'E', 'A', 'C', 'ES', 'I']
                    : ['Penalaran objektif'];
                $ratingLabels = $form === 'B' ? TechnicalAssessmentService::RATING_DIMENSIONS : [];
                $summary = $form === 'A' ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $summary->setName('Ringkasan Form '.$form);
                $summary->setSheetView((new SheetView)->setFreezeRow(2));
                $summaryHeaders = ['ID hasil', 'ID lamaran', 'Nama kandidat', 'Email', 'Posisi', 'Periode rekrutmen', 'Paket tes', 'Versi', 'Selesai ('.$timezone.')', 'Soal dijawab', 'Jumlah soal', ...$scoreLabels,
                    ...array_map(fn (string $label): string => $label.' (0–4)', $ratingLabels), 'Status verifikasi', 'Catatan assessor'];
                $writer->addRow($this->row($summaryHeaders, $header));
                $details = $writer->addNewSheetAndMakeItCurrent();
                $details->setName('Soal Jawaban Form '.$form);
                $details->setSheetView((new SheetView)->setFreezeRow(2));
                $details->setColumnWidth(65, 8, 9, 10, 11);
                $detailHeaders = ['ID hasil', 'Nama kandidat', 'Email', 'Versi', 'Tahap', 'Kode soal', 'Judul soal', 'Soal', 'Materi soal', 'Pilihan jawaban', 'Jawaban kandidat', 'Poin', 'Poin maksimum'];
                $writer->addRow($this->row($detailHeaders, $header));

                foreach ((clone $query)->where('form', $form)->with(['application.user', 'application.position', 'application.period'])->lazyById(100) as $attempt) {
                    $application = $attempt->application;
                    $identity = [$attempt->id, $application->user->name, $application->user->email, $attempt->version];
                    $answered = 0;
                    $questionCount = 0;
                    $writer->setCurrentSheet($details);
                    foreach ($attempt->sectionsForDisplay() as $index => $section) {
                        foreach ($section['questions'] as $question) {
                            $answer = $attempt->answers[$index][$question['id']] ?? null;
                            $hasAnswer = $answer !== null && $answer !== '';
                            $answered += (int) $hasAnswer;
                            $questionCount++;
                            $material = array_merge($section['material'] ?? [], $question['material'] ?? []);
                            if ($question['id'] === 'revised_decision') {
                                $material = array_merge($material, $section['followup_material'] ?? []);
                            }
                            $options = $question['options'] ?? [];
                            $choices = [];
                            foreach ($options as $value => $label) {
                                $choices[] = $value.'. '.$label;
                            }
                            $answerText = $hasAnswer ? (string) $answer : 'Belum dijawab';
                            if ($hasAnswer && isset($options[$answer])) {
                                $answerText .= ' · '.$options[$answer];
                            }
                            $weights = $attempt->package['scoring'][$question['id']] ?? [];
                            $writer->addRow($this->row([...$identity, $section['title'], $question['id'], $question['label'] ?? $question['id'], $question['prompt'],
                                $this->materialText($material), implode("\n", $choices), $answerText, $attempt->scores['items'][$question['id']] ?? null, $weights ? max($weights) : null], $text));
                        }
                    }
                    foreach ($attempt->conditionQuestions() as $question) {
                        $answer = $attempt->conditionAnswers()[$question['id']] ?? null;
                        $writer->addRow($this->row([...$identity, 'Catatan kondisi', $question['id'], $question['label'] ?? $question['id'], $question['prompt'], '', '',
                            $answer !== null && $answer !== '' ? (string) $answer : 'Belum diisi', null, null], $text));
                    }

                    $values = [$attempt->id, $application->id, $application->user->name, $application->user->email, $application->position->name, $application->period->name,
                        $attempt->package['title'], $attempt->version, $attempt->completed_at->copy()->timezone($timezone)->format('d/m/Y H:i:s'), $answered, $questionCount];
                    foreach ($scoreLabels as $label) {
                        $value = $attempt->scores[$label] ?? null;
                        $values[] = is_array($value) ? (implode(', ', $value) ?: 'Tidak ada') : $value;
                    }
                    foreach ($ratingLabels as $label) {
                        $values[] = $attempt->review['ratings'][$label] ?? null;
                    }
                    $values[] = $attempt->review['status'] ?? 'Belum dinilai';
                    $values[] = $attempt->review['notes'] ?? '';
                    $writer->setCurrentSheet($summary);
                    $writer->addRow($this->row($values, $text));
                    $count++;
                }
                if ($summary->getWrittenRowCount() === 1) {
                    $writer->setCurrentSheet($summary);
                    $writer->addRow($this->row(['Tidak ada hasil tes selesai sesuai filter.'], $text));
                }
                $summary->setAutoFilter(new AutoFilter(0, 1, count($summaryHeaders) - 1, $summary->getWrittenRowCount()));
                $details->setAutoFilter(new AutoFilter(0, 1, count($detailHeaders) - 1, $details->getWrittenRowCount()));
            }
        } finally {
            $writer->close();
        }
        AuditLog::record('technical_assessment.results_exported', $actor, $actor, metadata: ['count' => $count, 'search' => $search, 'application_id' => $applicationId]);
    }

    /** @param array<int, string|int|float|null> $values */
    private function row(array $values, Style $style): Row
    {
        return new Row(array_map(fn (string|int|float|null $value): Cell => is_string($value) ? new StringCell($value, $style) : Cell::fromValue($value), $values));
    }

    /** @param array<int, array<string, mixed>> $material */
    private function materialText(array $material): string
    {
        $lines = [];
        foreach ($material as $block) {
            if (isset($block['columns'])) {
                foreach ($block['columns'] as $document) {
                    $lines[] = $document['title']."\n".$this->materialText($document['material']);
                }
            } elseif (isset($block['items'])) {
                $lines[] = $block['heading']."\n".implode("\n", $block['items']);
            } elseif (isset($block['text'])) {
                $lines[] = $block['text'];
            } else {
                foreach ($block['rows'] as $row) {
                    $lines[] = implode(' | ', $row);
                }
            }
        }

        return implode("\n", $lines);
    }
}
