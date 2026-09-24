<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CandidateFormVersion;
use App\Models\FormIntake;
use App\Models\User;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class FormResponseExportService
{
    public function write(User $actor, array $filters, string $path): void
    {
        abort_unless(CandidateFormService::available(), 404);
        app(CandidateFormService::class)->admin($actor);
        $query = app(FormResponseQuery::class)->filtered($filters)->whereNotNull('submitted_at')->whereHas('latestRevision');
        $options = new Options;
        $options->DEFAULT_COLUMN_WIDTH = 28;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('415E9E')->setShouldWrapText();
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        $timezone = AppSetting::valueFor('timezone');
        $count = 0;
        $sheetCount = 0;
        try {
            $versions = CandidateFormVersion::whereIn('id', FormIntake::whereIn('id', (clone $query)->select('form_intake_id'))->select('candidate_form_version_id'))->orderBy('id')->get();
            foreach ($versions as $version) {
                $sheet = $sheetCount++ ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
                $sheet->setName('Form '.$version->candidate_form_id.' v'.$version->number.' #'.$version->id);
                $sheet->setSheetView((new SheetView)->setFreezeRow(2));
                $fields = array_values(array_filter($version->fields, fn ($field) => $field['type'] !== 'section'));
                $headers = ['Referensi', 'Nama lengkap', 'Email', 'Formulir', 'Versi', 'ID tautan', 'Posisi', 'Periode', 'Status', 'Akun terhubung', 'Dikirim ('.$timezone.')'];
                foreach ($fields as $index => $field) {
                    $headers[] = ($index + 1).'. '.$field['label'];
                    if ($field['type'] === 'file') {
                        $headers[] = ($index + 1).'. '.$field['label'].' — tautan unduh (login diperlukan)';
                    }
                }
                $writer->addRow(new Row(array_map(fn ($value) => new StringCell($value, $header), $headers)));
                $rows = (clone $query)->whereHas('intake', fn ($q) => $q->where('candidate_form_version_id', $version->id))
                    ->with('intake.position', 'intake.period', 'latestRevision', 'documents')->lazyById(200);
                foreach ($rows as $response) {
                    $revision = $response->latestRevision;
                    $values = [$response->reference, $revision->name, $response->email, $version->title, (string) $version->number, (string) $response->form_intake_id, $response->intake->position->name, $response->intake->period->name, $response->status === 'revision' ? 'Revisi (jawaban final terakhir)' : 'Terkirim', $response->user_id ? 'Ya' : 'Belum'];
                    $cells = array_map(fn ($value) => new StringCell($value, $text), $values);
                    $cells[] = Cell::fromValue($revision->submitted_at->copy()->timezone($timezone), (new Style)->setFormat('dd/mm/yyyy hh:mm'));
                    foreach ($fields as $field) {
                        $value = $revision->answers[$field['id']] ?? null;
                        if ($field['type'] === 'file') {
                            $documents = $response->documents->whereIn('id', $revision->document_ids)->where('field_id', $field['id']);
                            $cells[] = new StringCell($documents->pluck('original_name')->implode("\n"), $text);
                            $cells[] = new StringCell($documents->map(fn ($doc) => $doc->available() ? route('forms.document.download', $doc) : 'Belum tersedia: '.$doc->original_name)->implode("\n"), $text);
                        } elseif ($value !== null && $value !== '' && $field['type'] === 'number') {
                            $cells[] = Cell::fromValue((float) $value);
                        } elseif ($value && $field['type'] === 'date') {
                            $cells[] = Cell::fromValue(new \DateTimeImmutable($value), (new Style)->setFormat('dd/mm/yyyy'));
                        } else {
                            $value = $field['type'] === 'consent' && $value !== null ? ($value ? 'Ya' : 'Tidak') : $value;
                            // Explicit string cells prevent spreadsheet formulas from executing candidate input.
                            $cells[] = new StringCell(is_array($value) ? implode("\n", $value) : (string) ($value ?? ''), $text);
                        }
                    }
                    $writer->addRow(new Row($cells));
                    $count++;
                }
                $sheet->setAutoFilter(new AutoFilter(0, 1, count($headers) - 1, $sheet->getWrittenRowCount()));
            }
            if (! $sheetCount) {
                $writer->getCurrentSheet()->setName('Respons');
                $writer->addRow(Row::fromValues(['Tidak ada respons final sesuai filter.']));
            }
        } finally {
            $writer->close();
        }
        AuditLog::record('form.responses_exported', $actor, $actor, null, ['count' => $count]);
    }
}
