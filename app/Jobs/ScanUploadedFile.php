<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\UploadedFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ScanUploadedFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = UploadedFile::find($this->fileId);
        if (! $file || $file->scan_status !== ScanStatus::Pending) {
            return;
        }
        if (! config('submissions.scan_enabled')) {
            $file->update(['scan_status' => ScanStatus::Skipped, 'scan_message' => null]);

            return;
        }
        try {
            $binary = (new ExecutableFinder)->find(config('submissions.scanner_binary'));
            if (! $binary) {
                $this->failed();

                return;
            }
            $process = new Process([$binary, '--no-summary', '--', Storage::disk($file->disk)->path($file->path)]);
            $process->setTimeout(config('submissions.scanner_timeout'));
            $process->run();
            $status = match ($process->getExitCode()) {
                0 => ScanStatus::Clean,1 => ScanStatus::Rejected,default => ScanStatus::Failed
            };
            $file->update(['scan_status' => $status, 'scan_message' => match ($status) {
                ScanStatus::Clean => null,ScanStatus::Rejected => 'File ditolak pemeriksaan keamanan. Unggah file lain.',default => 'Layanan pemeriksaan belum tersedia. Coba lagi atau hubungi HR.'
            }]);
        } catch (\Throwable) {
            $this->failed();
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        UploadedFile::whereKey($this->fileId)->where('scan_status', 'pending')->update(['scan_status' => ScanStatus::Failed->value, 'scan_message' => 'Pemeriksaan gagal. Coba lagi atau hubungi HR.']);
    }
}
