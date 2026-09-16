<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\RecruitmentApplication;
use App\Models\UploadedFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupPortalFiles extends Command
{
    protected $signature = 'portal:cleanup {--dry-run : Tampilkan jumlah tanpa menghapus}';

    protected $description = 'Membersihkan unggahan sementara dan retensi yang telah diaktifkan';

    public function handle(): int
    {
        $count = 0;
        $disk = Storage::disk('private');
        $cutoff = now()->subHours(config('submissions.temporary_hours'))->timestamp;
        foreach (['quarantine', 'livewire-tmp'] as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                if ($disk->lastModified($path) >= $cutoff || UploadedFile::where('path', $path)->exists()) {
                    continue;
                }
                $count++;
                if (! $this->option('dry-run')) {
                    $disk->delete($path);
                }
            }
        }
        UploadedFile::whereDoesntHave('attachments')->where('created_at', '<', now()->subHours(config('submissions.temporary_hours')))->chunkById(100, function ($files) use (&$count) {
            foreach ($files as $file) {
                DB::transaction(function () use ($file, &$count) {
                    RecruitmentApplication::whereKey($file->recruitment_application_id)->lockForUpdate()->firstOrFail();
                    $file = UploadedFile::lockForUpdate()->find($file->id);
                    if (! $file || $file->attachments()->exists()) {
                        return;
                    }
                    $count++;
                    if (! $this->option('dry-run')) {
                        Storage::disk($file->disk)->delete($file->path);
                        $file->delete();
                    }
                });
            }
        });
        if (AppSetting::valueFor('retention_enabled')) {
            RecruitmentApplication::whereNull('purged_at')->whereNotNull('archived_at')->where('archived_at', '<', now()->subMonths(AppSetting::valueFor('retention_months')))->chunkById(25, function ($applications) use (&$count) {
                foreach ($applications as $application) {
                    DB::transaction(function () use ($application, &$count) {
                        $application = RecruitmentApplication::lockForUpdate()->findOrFail($application->id);
                        if ($application->purged_at || ! $application->archived_at || $application->archived_at->gte(now()->subMonths(AppSetting::valueFor('retention_months')))) {
                            return;
                        }
                        $count += $application->files()->count();
                        if ($this->option('dry-run')) {
                            return;
                        }
                        foreach ($application->files()->cursor() as $file) {
                            Storage::disk($file->disk)->delete($file->path);
                            $file->update(['original_name' => 'Dihapus sesuai retensi', 'size' => 0, 'scan_status' => 'rejected', 'scan_message' => 'Dihapus sesuai retensi.']);
                        }
                        foreach ($application->submissions as $submission) {
                            foreach ($submission->versions as $version) {
                                $version->update(['notes' => null, 'links' => []]);
                                $version->attachments()->update(['description' => null]);
                            }
                        }
                        $application->update(['purged_at' => now()]);
                        AuditLog::record('application.retention_purged', $application, null, 'Kebijakan retensi aktif');
                    });
                }
            });
        }
        $this->info(($this->option('dry-run') ? 'Pratinjau: ' : 'Selesai: ').$count.' file.');

        return self::SUCCESS;
    }
}
