<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Services\PsychometricService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PsychometricController extends Controller
{
    public function __construct(private PsychometricService $service) {}

    public function index(Request $request): Response
    {
        abort_unless(PsychometricService::available(), 404);
        abort_unless($request->user()->role === Role::Candidate, 403);
        $attempts = PsychometricAttempt::whereHas('application', fn ($q) => $q->where('user_id', $request->user()->id)->whereNull('archived_at')->whereNull('purged_at'))->with('test')->latest()->paginate(10);

        return response()->view('candidate.psychometrics', compact('attempts'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $attempt): Response
    {
        $record = $this->service->act($request->user(), $attempt, 'view');
        $section = $record->completed_at ? null : $record->test->sections[$record->section_index];

        return response()->view('candidate.psychometric-attempt', ['attempt' => $record, 'section' => $section])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $attempt): Response
    {
        $data = $request->validate(['action' => ['required', 'in:start,save,finish'], 'section' => ['required', 'integer', 'min:0', 'max:3'], 'revision' => ['sometimes', 'integer', 'min:0'], 'answers' => ['sometimes', 'array']]);
        $record = $this->service->act($request->user(), $attempt, $data['action'], $data + ['answers' => []]);
        if ($request->expectsJson()) {
            return response()->json(['revision' => $record->revision, 'section' => $record->section_index, 'completed' => $record->completed_at !== null, 'active' => $record->section_started_at !== null])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('candidate.psychometrics.show', $record);
    }

    public function image(Request $request, int $attempt, int $page): Response
    {
        $record = $this->service->act($request->user(), $attempt, 'view');
        abort_if($record->completed_at || now()->lt($record->opens_at), 404);
        $section = $record->test->sections[$record->section_index];
        $allowed = [$section['example'], ...($record->section_started_at ? $section['pages'] : [])];
        abort_unless(in_array($page, $allowed, true), 404);

        return $this->imageResponse($page, $record->test);
    }

    public function activity(Request $request, int $attempt): Response
    {
        $data = $request->validate([
            'event' => ['required', 'in:tab_hidden,window_blur,fullscreen_exit'],
            'section' => ['required', 'integer', 'min:0', 'max:3'],
        ]);
        $record = PsychometricAttempt::with(['application', 'test'])->findOrFail($attempt);
        $this->service->authorize($request->user(), $record);
        abort_unless(! $record->completed_at && $record->section_started_at && $record->section_index === $data['section'] && now()->lt($record->section_expires_at) && now()->lt($record->deadline), 409);
        AuditLog::record('psychometric.activity.'.$data['event'], $record, $request->user(), metadata: ['section' => $record->section_index + 1]);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, int $test, int $page): Response
    {
        $this->service->admin($request->user());
        abort_unless($page >= 2 && $page <= 14, 404);

        return $this->imageResponse($page, PsychometricTest::findOrFail($test));
    }

    private function imageResponse(int $page, PsychometricTest $test): Response
    {
        abort_unless(is_file($this->service->assetPath($page, $test)), 404);

        return response()->file($this->service->assetPath($page, $test), ['Content-Type' => mime_content_type($this->service->assetPath($page, $test)), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'])->setPrivate();
    }

    public function questionImage(Request $request, int $attempt, int $section, int $question, string $part): Response
    {
        $record = $this->service->act($request->user(), $attempt, 'view');
        abort_if($record->completed_at || now()->lt($record->opens_at) || ! $record->section_started_at || $record->section_index !== $section, 404);

        return $this->customImageResponse($record->test, $section, $question, $part);
    }

    public function questionPreview(Request $request, int $test, int $section, int $question, string $part): Response
    {
        $this->service->admin($request->user());

        return $this->customImageResponse(PsychometricTest::findOrFail($test), $section, $question, $part);
    }

    private function customImageResponse(PsychometricTest $test, int $section, int $question, string $part): Response
    {
        $path = $test->sections[$section]['images'][$question][$part] ?? null;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return response()->file(Storage::disk('private')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'])->setPrivate();
    }
}
