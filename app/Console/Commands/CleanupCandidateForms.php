<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\FormAccessToken;
use App\Models\FormDocument;
use App\Models\FormResponse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CleanupCandidateForms extends Command
{
    protected $signature = 'forms:cleanup {--dry-run : Hanya tampilkan jumlah target}';

    protected $description = 'Bersihkan token kedaluwarsa, unggahan lepas, dan respons sesuai retensi formulir';

    public function handle(): int
    {
        if (! Schema::hasTable('form_access_tokens')) {
            $this->info('Migrasi formulir belum diterapkan. Tidak ada data yang dibersihkan.');

            return self::SUCCESS;
        }
        $count = 0;
        FormResponse::query()->where(function ($q) {
            $q->where(fn ($q) => $q->whereNull('submitted_at')->whereNull('user_id')->where('updated_at', '<', now()->subDays(config('candidate_forms.draft_retention_days'))));
            if (config('candidate_forms.delete_final_responses')) {
                $q->orWhere(fn ($q) => $q->whereNotNull('submitted_at')->whereNull('user_id')->where('updated_at', '<', now()->subDays(config('candidate_forms.unlinked_retention_days'))))
                    ->orWhere(fn ($q) => $q->whereNotNull('user_id')->whereHas('application', fn ($q) => $q->whereNotNull('archived_at'))->where('updated_at', '<', now()->subDays(config('candidate_forms.linked_retention_days'))));
            }
        })->chunkById(100, function ($responses) use (&$count) {
            foreach ($responses as $response) {
                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($response) {
                        $current = FormResponse::lockForUpdate()->find($response->id);
                        if (! $current || ! $current->updated_at->equalTo($response->updated_at)) {
                            return;
                        }
                        foreach ($current->documents as $document) {
                            if (Storage::disk('private')->exists($document->path) && ! Storage::disk('private')->delete($document->path)) {
                                throw new \RuntimeException('Gagal membersihkan dokumen formulir.');
                            }
                        }
                        AuditLog::record('form.retention_deleted', $current);
                        $current->documents()->delete();
                        $current->revisions()->delete();
                        FormAccessToken::where('form_intake_id', $current->form_intake_id)->where('email', $current->email)->delete();
                        $current->delete();
                    });
                }
                $count++;
            }
        });
        if (! $this->option('dry-run')) {
            FormAccessToken::where('expires_at', '<', now()->subDay())->delete();
            FormDocument::where('selected', false)->where('updated_at', '<', now()->subDay())->chunkById(100, function ($documents) {
                foreach ($documents as $document) {
                    DB::transaction(function () use ($document) {
                        $response = FormResponse::lockForUpdate()->find($document->form_response_id);
                        if (! $response || $response->revisions()->get()->contains(fn ($revision) => in_array($document->id, $revision->document_ids, true))) {
                            return;
                        }
                        if (! Storage::disk('private')->exists($document->path) || Storage::disk('private')->delete($document->path)) {
                            $document->delete();
                        }
                    });
                }
            });
        }
        $this->info($count.' respons sesuai kriteria retensi'.($this->option('dry-run') ? ' (dry run).' : ' diproses.'));

        return self::SUCCESS;
    }
}
