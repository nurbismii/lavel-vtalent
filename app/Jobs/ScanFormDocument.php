<?php

namespace App\Jobs;

use App\Models\FormDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ScanFormDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = FormDocument::find($this->fileId);
        if (! $file || $file->scan_status !== 'pending') {
            return;
        }
        if (! config('submissions.scan_enabled')) {
            $file->update(['scan_status' => 'skipped']);

            return;
        }
        try {
            $binary = (new ExecutableFinder)->find(config('submissions.scanner_binary'));
            if (! $binary) {
                $this->failed();

                return;
            }
            $process = new Process([$binary, '--no-summary', '--', Storage::disk('private')->path($file->path)]);
            $process->setTimeout(config('submissions.scanner_timeout'));
            $process->run();
            $status = match ($process->getExitCode()) {
                0 => 'clean', 1 => 'rejected', default => 'failed'
            };
            $file->update(['scan_status' => $status, 'scan_message' => match ($status) {
                'clean' => null, 'rejected' => 'File ditolak. Unggah dokumen lain.', default => 'Pemeriksaan gagal. Coba kembali atau hubungi HR.'
            }]);
        } catch (\Throwable) {
            $this->failed();
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        FormDocument::whereKey($this->fileId)->where('scan_status', 'pending')->update(['scan_status' => 'failed', 'scan_message' => 'Pemeriksaan belum tersedia. Coba kembali atau hubungi HR.']);
    }
}
