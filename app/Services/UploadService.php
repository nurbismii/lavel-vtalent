<?php

namespace App\Services;

use App\Enums\ScanStatus;
use App\Enums\SubmissionType;
use App\Jobs\ScanUploadedFile;
use App\Models\AppSetting;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Http\UploadedFile as HttpFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;

class UploadService
{
    public function store(Submission $submission, User $actor, HttpFile $upload, string $purpose): UploadedFile
    {
        Gate::forUser($actor)->authorize('update', $submission);
        $allowed = $submission->type === SubmissionType::Portfolio ? ['portfolio_main', 'portfolio_evidence'] : ['technical_result'];
        abort_unless(in_array($purpose, $allowed, true), 422);
        $limits = AppSetting::valueFor('uploads')[$purpose];
        Validator::make(['upload' => $upload], ['upload' => ['required', 'file', 'max:'.($limits['max_mb'] * 1024), 'extensions:'.implode(',', $limits['extensions']), 'mimes:'.implode(',', $limits['extensions'])]])->validate();
        $this->validateOffice($upload);
        try {
            $path = $upload->storeAs('quarantine', Str::uuid().'.'.strtolower($upload->getClientOriginalExtension()), 'private');
        } catch (UnableToWriteFile $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'upload' => 'File belum berhasil disimpan. Silakan coba lagi. Jika tetap gagal, hubungi HR untuk memeriksa penyimpanan server.',
            ]);
        }
        try {
            return DB::transaction(function () use ($submission, $actor, $upload, $purpose, $path, $limits) {
                $submission = app(SubmissionService::class)->locked($submission);
                Gate::forUser($actor)->authorize('update', $submission);
                $used = UploadedFile::where('recruitment_application_id', $submission->recruitment_application_id)->sum('size');
                if ($used + $upload->getSize() > AppSetting::valueFor('quota_mb') * 1024 * 1024) {
                    throw ValidationException::withMessages(['upload' => 'Kuota lamaran penuh, termasuk histori. Hubungi HR.']);
                }
                $draft = $submission->versions()->where('status', 'draft')->first();
                $attached = $draft?->attachments()->where('purpose', $purpose)->count() ?? 0;
                $availableStatuses = config('submissions.scan_enabled') ? ['pending', 'clean'] : ['pending', 'clean', 'skipped', 'failed'];
                $staged = UploadedFile::where('submission_id', $submission->id)->where('purpose', $purpose)->whereDoesntHave('attachments')->whereIn('scan_status', $availableStatuses)->count();
                if (($purpose === 'portfolio_main' && $staged >= 1) || ($purpose !== 'portfolio_main' && $attached + $staged >= $limits['max_files'])) {
                    throw ValidationException::withMessages(['upload' => 'Jumlah file sudah mencapai batas. Hapus unggahan yang tidak digunakan.']);
                }
                $file = UploadedFile::create(['recruitment_application_id' => $submission->recruitment_application_id, 'submission_id' => $submission->id, 'uploader_id' => $actor->id, 'purpose' => $purpose, 'path' => $path, 'original_name' => Str::limit(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 240, ''), 'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'checksum' => hash_file('sha256', $upload->getRealPath()), 'scan_status' => config('submissions.scan_enabled') ? ScanStatus::Pending : ScanStatus::Skipped]);
                if ($file->scan_status === ScanStatus::Skipped) {
                    return $file;
                }
                DB::afterCommit(function () use ($file): void {
                    try {
                        ScanUploadedFile::dispatch($file->id);
                    } catch (\Throwable) {
                        $file->update(['scan_status' => ScanStatus::Failed, 'scan_message' => 'Antrean pemeriksaan gagal. Coba lagi.']);
                    }
                });

                return $file;
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    private function validateOffice(HttpFile $upload): void
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        if (! in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            return;
        }
        $zip = new \ZipArchive;
        if ($zip->open($upload->getRealPath()) !== true) {
            throw ValidationException::withMessages(['upload' => 'Dokumen Office tidak valid.']);
        }
        try {
            $root = match ($extension) {
                'docx' => 'word/document.xml','xlsx' => 'xl/workbook.xml','pptx' => 'ppt/presentation.xml'
            };
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($root) === false) {
                throw ValidationException::withMessages(['upload' => 'Isi dokumen tidak sesuai ekstensi Office.']);
            }
            $stat = $zip->statName('[Content_Types].xml');
            if ($stat['size'] > 1024 * 1024) {
                throw ValidationException::withMessages(['upload' => 'Struktur dokumen Office tidak valid.']);
            }
            $types = $zip->getFromName('[Content_Types].xml');
            if (stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                throw ValidationException::withMessages(['upload' => 'Dokumen bermakro tidak diizinkan.']);
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (stripos($zip->getNameIndex($i), 'vbaProject') !== false) {
                    throw ValidationException::withMessages(['upload' => 'Dokumen bermakro tidak diizinkan.']);
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function retry(UploadedFile $file, User $actor): void
    {
        DB::transaction(function () use ($file, $actor) {
            $submission = app(SubmissionService::class)->locked($file->submission);
            Gate::forUser($actor)->authorize('update', $submission);
            $file = UploadedFile::lockForUpdate()->findOrFail($file->id);
            abort_unless($file->uploader_id === $actor->id && $file->scan_status === ScanStatus::Failed, 422);
            $file->update(['scan_status' => config('submissions.scan_enabled') ? ScanStatus::Pending : ScanStatus::Skipped, 'scan_message' => null]);
            if ($file->scan_status === ScanStatus::Skipped) {
                return;
            }
            DB::afterCommit(function () use ($file): void {
                try {
                    ScanUploadedFile::dispatch($file->id);
                } catch (\Throwable) {
                    $file->update(['scan_status' => ScanStatus::Failed, 'scan_message' => 'Antrean pemeriksaan gagal. Coba lagi.']);
                }
            });
        });
    }

    public function discard(UploadedFile $file, User $actor): void
    {
        DB::transaction(function () use ($file, $actor) {
            $submission = app(SubmissionService::class)->locked($file->submission);
            Gate::forUser($actor)->authorize('update', $submission);
            $file = UploadedFile::lockForUpdate()->findOrFail($file->id);
            abort_unless($file->uploader_id === $actor->id && ! $file->attachments()->exists(), 422);
            Storage::disk($file->disk)->delete($file->path);
            $file->delete();
        });
    }
}
