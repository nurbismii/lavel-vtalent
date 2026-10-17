<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\TechnicalAssessmentAttempt;
use App\Services\TechnicalAssessmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TechnicalAssessmentController extends Controller
{
    public function __construct(private TechnicalAssessmentService $service) {}

    public function index(Request $request): Response
    {
        abort_unless(TechnicalAssessmentService::available(), 404);
        abort_unless($request->user()->role === Role::Candidate, 403);
        $attempts = TechnicalAssessmentAttempt::whereHas('application', fn ($query) => $query->where('user_id', $request->user()->id)->whereNull('archived_at')->whereNull('purged_at'))->withExists('completedFormA')->latest()->paginate(10);

        return response()->view('candidate.technical-assessments', compact('attempts'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $attempt): Response
    {
        $record = $this->service->act($request->user(), $attempt, 'view');
        if ($request->expectsJson()) {
            return $this->state($record);
        }
        $section = $record->package['sections'][$record->section_index] ?? null;

        return response()->view('candidate.technical-assessment-attempt', ['attempt' => $record, 'section' => $section])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $attempt): Response
    {
        $data = $request->validate([
            'action' => ['required', 'in:consent,start,save,finish,conditions'],
            'section' => ['sometimes', 'integer', 'min:0', 'max:4'], 'revision' => ['sometimes', 'integer', 'min:0'],
            'answers' => ['sometimes', 'array'], 'consent' => ['sometimes'], 'conditions' => ['sometimes', 'array'], 'confirmation' => ['sometimes'],
        ]);
        foreach (['section', 'revision'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        $record = $this->service->act($request->user(), $attempt, $data['action'], $data + ['answers' => []]);
        if ($request->expectsJson()) {
            return $this->state($record);
        }

        return redirect()->route('candidate.technical-assessments.show', $record)->with('status', $data['action'] === 'conditions' ? 'Catatan kondisi dan konfirmasi tersimpan.' : 'Status tes diperbarui.');
    }

    public function activity(Request $request, int $attempt): Response
    {
        $data = $request->validate(['event' => ['required', 'string'], 'section' => ['required', 'integer', 'min:0', 'max:4']]);
        $this->service->activity($request->user(), $attempt, $data['event'], (int) $data['section']);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    private function state(TechnicalAssessmentAttempt $attempt): Response
    {
        return response()->json([
            'section' => $attempt->section_index, 'revision' => $attempt->revision,
            'active' => $attempt->section_started_at !== null, 'completed' => $attempt->completed_at !== null,
            'remaining' => $attempt->section_expires_at ? max(0, (int) ceil(now()->diffInSeconds($attempt->section_expires_at, false))) : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
