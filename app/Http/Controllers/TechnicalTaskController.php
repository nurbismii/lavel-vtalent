<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\TechnicalTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TechnicalTaskController extends Controller
{
    public function __invoke(Request $request, TechnicalTask $task): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->active && $user->role === Role::Candidate && ! $user->must_change_password, 403);
        abort_unless($task->starts_at->lte(now()) && $user->applications()->whereNull('archived_at')->where('position_id', $task->position_id)->where('recruitment_period_id', $task->recruitment_period_id)->exists(), 403);
        abort_unless(Storage::disk('private')->exists($task->path), 404);

        return Storage::disk('private')->download($task->path, 'soal-tes-teknis.pdf', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
