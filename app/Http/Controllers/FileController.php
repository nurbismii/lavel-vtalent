<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\SubmissionAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function __invoke(Request $request, SubmissionAttachment $attachment): mixed
    {
        $attachment->load('version.submission.application', 'file');
        $submission = $attachment->version->submission;
        abort_unless(Gate::allows('view', $submission), 404);
        $user = $request->user();
        if ($user->role === Role::Admin) {
            abort_unless($user->getAppAuthenticationSecret() && $attachment->version->status === 'final', 404);
        }
        abort_unless($attachment->file->scan_status->available() && $attachment->file->submission_id === $submission->id, 404);
        abort_unless(Storage::disk($attachment->file->disk)->exists($attachment->file->path), 404);
        $preview = $request->routeIs('files.preview');
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($preview) {
            $mime = Storage::disk($attachment->file->disk)->mimeType($attachment->file->path);
            abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 415);
            $headers['Content-Type'] = $mime;
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; frame-ancestors 'self'";
            $headers['Referrer-Policy'] = 'no-referrer';
        }
        if ($user->role === Role::Admin) {
            AuditLog::record($preview ? 'file.previewed' : 'file.downloaded', $submission, $user, null, ['attachment_id' => $attachment->id, 'version' => $attachment->version->number]);
        }

        return Storage::disk($attachment->file->disk)->response($attachment->file->path, $attachment->file->original_name, $headers, $preview ? 'inline' : 'attachment');
    }
}
