<?php

use App\Enums\Role;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\TechnicalTaskController;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\FormHeaders;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\FormResponse;
use App\Models\SubmissionVersion;
use App\Services\CandidateFormService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::view('/login', 'auth.login')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::view('/forgot-password', 'auth.forgot')->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
Route::get('/reset-password/{token}', fn (Request $request, string $token) => view('auth.reset', ['token' => $token, 'email' => $request->query('email', '')]))->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
Route::view('/privacy', 'privacy')->name('privacy');
Route::middleware([FormHeaders::class, 'throttle:forms-public'])->group(function () {
    $controller = PublicFormController::class;
    Route::get('/forms/{intake:slug}', [$controller, 'show'])->name('forms.show');
    Route::post('/forms/{intake:slug}/access', [$controller, 'access'])->middleware('throttle:forms-email')->name('forms.access');
    Route::get('/form-access/{token}', [$controller, 'verify'])->where('token', '[A-Za-z0-9]{64}')->name('forms.verify');
    Route::post('/form-access/{token}', [$controller, 'consume'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:forms-verification')->name('forms.consume');
    Route::get('/form-responses/{response:reference}', [$controller, 'response'])->name('forms.response');
    Route::post('/form-responses/{response:reference}', [$controller, 'save'])->name('forms.save');
    Route::post('/form-responses/{response:reference}/submit', [$controller, 'submit'])->name('forms.submit');
    Route::post('/form-responses/{response:reference}/upload', [$controller, 'upload'])->middleware('throttle:forms-upload')->name('forms.upload');
    Route::post('/form-documents/{document}', [$controller, 'documentAction'])->name('forms.document.action');
    Route::get('/form-documents/{document}', [$controller, 'download'])->name('forms.document.download');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', EnsurePortalAccess::class])->group(function () {
    Route::get('/portal/forms', [PublicFormController::class, 'mine'])->middleware(FormHeaders::class)->name('candidate.forms');
    Route::get('/portal/technical-tasks/{task}/download', TechnicalTaskController::class)->name('candidate.task.download');
    Route::view('/change-password', 'auth.change')->name('password.initial');
    Route::post('/change-password', [AuthController::class, 'change'])->name('password.initial.store');
    Route::get('/files/{attachment}', FileController::class)->name('files.download');
    Route::get('/files/{attachment}/preview', FileController::class)->name('files.preview');
    Route::get('/portal', function (Request $request) {
        if ($request->user()->role === Role::Admin) {
            return redirect('/admin');
        }
        $application = $request->user()->applications()->whereNull('archived_at')->with(['position', 'period', 'submissions.currentVersion'])->first();

        $candidateForms = CandidateFormService::available()
            ? FormResponse::where('user_id', $request->user()->id)->whereHas('application', fn ($q) => $q->whereNull('archived_at')->whereNull('purged_at'))->with('intake.version.form', 'intake.position', 'intake.period', 'application')->latest()->limit(6)->get()
            : collect();

        return view('candidate.dashboard', compact('application', 'candidateForms'));
    })->name('candidate.dashboard');
    Route::get('/portal/submissions/{submission}', SubmissionForm::class)->name('candidate.submission');
    Route::get('/portal/history', function (Request $request) {
        abort_unless($request->user()->role === Role::Candidate, 403);
        $versions = SubmissionVersion::where('status', 'final')->whereHas('submission.application', fn ($q) => $q->where('user_id', $request->user()->id))->with(['submission.application.position', 'attachments.file'])->latest('submitted_at')->paginate(10);

        return view('candidate.history', compact('versions'));
    })->name('candidate.history');
    Route::get('/portal/profile', fn () => view('candidate.profile'))->name('candidate.profile');
    Route::post('/portal/profile', function (Request $request) {
        abort_unless($request->user()->role === Role::Candidate, 403);
        $data = $request->validate(['phone' => 'nullable|string|max:30|regex:/^[+0-9() .-]+$/']);
        $request->user()->profile()->updateOrCreate(['user_id' => $request->user()->id], $data);

        return back()->with('status', 'Profil berhasil disimpan.');
    })->name('candidate.profile.save');
});
