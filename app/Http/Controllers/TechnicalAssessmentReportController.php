<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\RecruitmentApplication;
use App\Services\TechnicalAssessmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TechnicalAssessmentReportController extends Controller
{
    public function __invoke(Request $request, RecruitmentApplication $application): Response
    {
        abort_unless(TechnicalAssessmentService::available(), 404);
        app(TechnicalAssessmentService::class)->admin($request->user());
        abort_unless($request->user()->getAppAuthenticationSecret(), 403);
        abort_if($application->purged_at, 404);
        $application->load(['user', 'position', 'period']);
        $attempts = $application->technicalAssessmentAttempts()->whereIn('form', ['A', 'B'])->whereNotNull('completed_at')->orderBy('form')->orderBy('id')->get();
        abort_if($attempts->isEmpty(), 404, 'Belum ada hasil Form A atau Form B yang selesai.');
        $timezone = AppSetting::valueFor('timezone');
        AuditLog::record('technical_assessment.report_opened', $application, $request->user(), metadata: ['count' => $attempts->count(), 'attempt_ids' => $attempts->modelKeys()]);

        return response()->view('filament.pages.technical-assessment-report', compact('application', 'attempts', 'timezone'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
