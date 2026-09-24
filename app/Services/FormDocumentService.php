<?php

namespace App\Services;

use App\Jobs\ScanFormDocument;
use App\Models\AppSetting;
use App\Models\FormDocument;
use App\Models\FormResponse;
use App\Models\RecruitmentApplication;
use App\Models\UploadedFile;
use Illuminate\Http\UploadedFile as HttpFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormDocumentService
{
    public function upload(FormResponse $response, string $fieldId, HttpFile $upload): FormDocument
    {
        $field = collect($response->intake->version->fields)->firstWhere('id', $fieldId);
        abort_unless($field && $field['type'] === 'file', 422);
        Validator::make(['upload' => $upload], ['upload' => ['required', 'file', 'max:'.(min($field['max_mb'], config('candidate_forms.max_mb')) * 1024), 'extensions:'.implode(',', $field['extensions']), 'mimes:'.implode(',', $field['extensions'])]])->validate();
        if (strtolower($upload->getClientOriginalExtension()) === 'docx') {
            $zip = new \ZipArchive;
            if ($zip->open($upload->getRealPath()) !== true) {
                throw ValidationException::withMessages(['upload' => 'Dokumen Office tidak valid.']);
            }
            try {
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('word/document.xml') === false) {
                    throw ValidationException::withMessages(['upload' => 'Struktur DOCX tidak valid.']);
                }
                $stat = $zip->statName('[Content_Types].xml');
                if ($stat['size'] > 1024 * 1024) {
                    throw ValidationException::withMessages(['upload' => 'Struktur DOCX terlalu besar.']);
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

        return Cache::lock('candidate-forms-upload-quota', 120)->block(10, function () use ($response, $field, $upload) {
            $path = null;
            try {
                $file = DB::transaction(function () use ($response, $field, $upload, &$path) {
                    $response = FormResponse::lockForUpdate()->findOrFail($response->id);
                    abort_unless($response->editable(), 422, 'Pengisian sudah terkunci.');
                    $used = $response->documents()->sum('size');
                    if ($response->recruitment_application_id) {
                        RecruitmentApplication::whereKey($response->recruitment_application_id)->lockForUpdate()->firstOrFail();
                        $applicationUsed = FormDocument::whereHas('response', fn ($q) => $q->where('recruitment_application_id', $response->recruitment_application_id))->sum('size')
                            + UploadedFile::where('recruitment_application_id', $response->recruitment_application_id)->sum('size');
                        if ($applicationUsed + $upload->getSize() > AppSetting::valueFor('quota_mb') * 1024 * 1024) {
                            throw ValidationException::withMessages(['upload' => 'Kuota lamaran penuh, termasuk histori. Hubungi HR.']);
                        }
                    }
                    if ($used + $upload->getSize() > config('candidate_forms.quota_mb') * 1024 * 1024
                        || FormDocument::sum('size') + $upload->getSize() > config('candidate_forms.global_quota_mb') * 1024 * 1024) {
                        throw ValidationException::withMessages(['upload' => 'Kuota penyimpanan penuh. Hubungi HR.']);
                    }
                    if ($response->documents()->where('field_id', $field['id'])->where('selected', true)->count() >= $field['max_files']) {
                        throw ValidationException::withMessages(['upload' => 'Batas jumlah file tercapai. Lepaskan file lama sebelum mengganti.']);
                    }
                    $path = $upload->storeAs('candidate-forms', Str::uuid().'.'.strtolower($upload->getClientOriginalExtension()), 'private');
                    if (! $path) {
                        throw new \RuntimeException('Penyimpanan gagal.');
                    }
                    $file = $response->documents()->create(['field_id' => $field['id'], 'path' => $path, 'original_name' => Str::limit(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 240, ''), 'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'scan_status' => config('submissions.scan_enabled') ? 'pending' : 'skipped']);
                    $response->increment('lock_version');

                    return $file;
                });
            } catch (\Throwable $exception) {
                if ($path) {
                    Storage::disk('private')->delete($path);
                }
                throw $exception;
            }
            $this->dispatch($file);

            return $file;
        });
    }

    public function dispatch(FormDocument $file): void
    {
        if ($file->scan_status !== 'pending') {
            return;
        }
        try {
            ScanFormDocument::dispatch($file->id);
        } catch (\Throwable $exception) {
            report($exception);
            $file->update(['scan_status' => 'failed', 'scan_message' => 'Antrean pemeriksaan gagal. Coba kembali.']);
        }
    }

    public function remove(FormDocument $file): void
    {
        DB::transaction(function () use ($file) {
            $response = FormResponse::lockForUpdate()->findOrFail($file->form_response_id);
            abort_unless($response->editable(), 422);
            $file = FormDocument::lockForUpdate()->findOrFail($file->id);
            $file->update(['selected' => false]);
            $response->increment('lock_version');
        });
    }

    public function retry(FormDocument $file): void
    {
        DB::transaction(function () use ($file) {
            $response = FormResponse::lockForUpdate()->findOrFail($file->form_response_id);
            abort_unless($response->editable(), 422);
            $file = FormDocument::lockForUpdate()->findOrFail($file->id);
            abort_unless($file->selected && $file->scan_status === 'failed', 422);
            $file->update(['scan_status' => config('submissions.scan_enabled') ? 'pending' : 'skipped', 'scan_message' => null]);
            DB::afterCommit(fn () => $this->dispatch($file));
        });
    }
}
