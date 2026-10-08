<?php

namespace App\Jobs;

use App\Models\PortfolioExport;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\PortfolioExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class BuildPortfolioExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportId)
    {
        $this->onConnection(config('submissions.portfolio_export.connection'));
        $this->onQueue('exports');
    }

    /**
     * Execute the job.
     */
    public function handle(PortfolioExportService $service): void
    {
        $export = PortfolioExport::find($this->exportId);
        if (! $export || $export->expires_at->isPast() || ! PortfolioExport::whereKey($export->id)->where('status', 'pending')->update(['status' => 'processing'])) {
            return;
        }
        try {
            $actor = User::findOrFail($export->actor_id);
            $disk = Storage::disk('private');
            $disk->makeDirectory('exports');
            $query = RecruitmentApplication::whereKey($export->application_ids)->whereNull('purged_at');
            if ($query->count() !== count($export->application_ids)) {
                throw new \RuntimeException('Data kandidat telah berubah.');
            }
            $count = $service->write($actor, $query, $disk->path($export->path));
            $export->update(['status' => 'ready', 'candidate_count' => $count, 'expires_at' => now()->addDay(), 'error' => null]);
        } catch (\Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        $export = PortfolioExport::find($this->exportId);
        if ($export && $export->status !== 'ready') {
            Storage::disk('private')->delete([$export->path, $export->path.'.xlsx']);
            $export->update(['status' => 'failed', 'error' => 'Export gagal. Periksa worker, ruang penyimpanan, dan izin akses; lalu buat export ulang.']);
        }
    }
}
