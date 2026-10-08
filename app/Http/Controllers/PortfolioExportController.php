<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PortfolioExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortfolioExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PortfolioExport $export): BinaryFileResponse
    {
        $user = $request->user()->fresh();
        abort_unless($user->active && ! $user->must_change_password && $user->role === Role::Admin && $user->getAppAuthenticationSecret() && $export->actor_id === $user->id, 403);
        abort_unless($export->status === 'ready' && $export->expires_at->isFuture(), 404);
        $disk = Storage::disk('private');
        abort_unless($disk->exists($export->path), 404);
        AuditLog::record('portfolio.export_downloaded', $export, $user);

        return response()->download($disk->path($export->path), 'portofolio-'.$export->id.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
