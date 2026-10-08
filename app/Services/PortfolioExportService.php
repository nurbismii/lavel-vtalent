<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

class PortfolioExportService
{
    public function write(User $actor, Builder $query, string $path): int
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->active && ! $actor->must_change_password && $actor->role === Role::Admin && $actor->getAppAuthenticationSecret(), 403);
        $limit = (int) config('submissions.portfolio_export.max_candidates');
        $applications = (clone $query)->reorder('id')->limit($limit + 1)->with([
            'user', 'position', 'period', 'submissions',
            'submissions.versions' => fn ($versions) => $versions->where('status', 'final')->orderByDesc('number')->limit(1),
            'submissions.versions.attachments.file',
        ])->lazyById(100);
        $applicationIds = [];
        $count = (clone $query)->count();
        if ($count === 0 || $count > $limit) {
            throw ValidationException::withMessages(['portfolioExport' => "Pilih filter dengan 1–$limit kandidat untuk export offline."]);
        }

        $rows = [];
        $files = [];
        $bytes = 0;
        foreach ($applications as $application) {
            $applicationIds[] = $application->id;
            $candidateName = Str::slug(mb_substr($application->user->name, 0, 80)) ?: 'kandidat';
            $folder = 'Kandidat/'.$candidateName.'-'.$application->id;
            $submission = $application->submissions->firstWhere('type', SubmissionType::Portfolio);
            $version = $submission?->versions->first();
            $main = '';
            $unavailable = 0;
            $included = 0;
            if ($submission) {
                $submission->setRelation('application', $application);
                abort_unless(Gate::forUser($actor)->allows('view', $submission), 403);
            }
            foreach ($version?->attachments ?? [] as $attachment) {
                $file = $attachment->file;
                if (! in_array($attachment->purpose, ['portfolio_main', 'portfolio_evidence'], true)) {
                    continue;
                }
                if (! $file || $file->submission_id !== $submission->id || ! $file->scan_status->available()) {
                    $unavailable++;

                    continue;
                }
                $disk = Storage::disk($file->disk);
                $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
                if (! $disk->exists($file->path) || ! in_array($extension, ['pdf', 'docx', 'jpg', 'jpeg', 'png'], true) || ($attachment->purpose === 'portfolio_main' && $extension !== 'pdf')) {
                    $unavailable++;

                    continue;
                }
                $bytes += $disk->size($file->path);
                $entry = $attachment->purpose === 'portfolio_main'
                    ? $folder.'/'.$candidateName.'.pdf'
                    : $folder.'/Lampiran/'.$attachment->id.'-'.(Str::slug(mb_substr(pathinfo($file->original_name, PATHINFO_FILENAME), 0, 80)) ?: 'lampiran').'.'.$extension;
                if ($attachment->purpose === 'portfolio_main') {
                    $main = $entry;
                }
                $files[] = ['source' => $disk->path($file->path), 'entry' => $entry];
                $included++;
            }
            $rows[] = [
                'values' => [(string) $application->id, $application->user->name, $application->user->email, $application->position->name, $application->period->name, $submission?->status->label() ?? 'Belum ada penugasan', $version ? (string) $version->number : '', $version?->submitted_at?->timezone(AppSetting::valueFor('timezone'))->format('d M Y H:i') ?? '', "$included dokumen; $unavailable tidak tersedia".($main === '' ? '; PDF utama tidak tersedia' : '')],
                'folder' => $folder,
                'main' => $main,
            ];
        }

        $xlsx = $path.'.xlsx';
        $zip = new ZipArchive;
        $opened = false;
        try {
            $options = new Options;
            $options->DEFAULT_COLUMN_WIDTH = 28;
            $writer = new Writer($options);
            $writer->openToFile($xlsx);
            try {
                $sheet = $writer->getCurrentSheet();
                $sheet->setName('Daftar Kandidat');
                $sheet->setSheetView((new SheetView)->setFreezeRow(3));
                $text = (new Style)->setFormat('@')->setShouldWrapText();
                $writer->addRow(Row::fromValues(['Ekstrak ZIP terlebih dahulu. Simpan Excel dan folder Kandidat bersama. Hanya dokumen versi final terakhir disertakan.']));
                $writer->addRow(new Row(array_map(fn ($value) => new StringCell($value, (new Style)->setFontBold()), ['ID Lamaran', 'Nama', 'Email', 'Posisi', 'Periode', 'Status', 'Versi final', 'Dikumpulkan', 'Keterangan', 'Portofolio', 'Folder kandidat'])));
                foreach ($rows as $row) {
                    $cells = array_map(fn ($value) => new StringCell($value, $text), $row['values']);
                    $cells[] = $row['main'] !== '' ? new FormulaCell('=HYPERLINK("'.$row['main'].'","Buka PDF")', null) : new StringCell('Tidak tersedia', $text);
                    $cells[] = new FormulaCell('=HYPERLINK("'.$row['folder'].'/","Buka folder")', null);
                    $writer->addRow(new Row($cells));
                }
            } finally {
                $writer->close();
            }
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Tidak dapat membuat ZIP portofolio.');
            }
            $opened = true;
            if (! $zip->addFile($xlsx, 'Daftar-Kandidat.xlsx')) {
                throw new RuntimeException('Tidak dapat menambahkan Excel.');
            }
            foreach ($rows as $row) {
                if (! $zip->addEmptyDir($row['folder'])) {
                    throw new RuntimeException('Tidak dapat membuat folder kandidat.');
                }
            }
            foreach ($files as $file) {
                if (! $zip->addFile($file['source'], $file['entry'])) {
                    throw new RuntimeException('Tidak dapat menambahkan dokumen.');
                }
            }
            $closed = $zip->close();
            $opened = false;
            if (! $closed) {
                throw new RuntimeException('ZIP portofolio tidak selesai dibuat.');
            }
            AuditLog::record('portfolio.offline_exported', $actor, $actor, metadata: ['application_ids' => $applicationIds, 'documents' => count($files), 'bytes' => $bytes]);
        } catch (\Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        } finally {
            if (is_file($xlsx)) {
                unlink($xlsx);
            }
        }

        return count($rows);
    }
}
