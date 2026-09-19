<?php

use App\Enums\Role;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TechnicalTaskController;
use App\Http\Middleware\EnsurePortalAccess;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\SubmissionVersion;
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
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', EnsurePortalAccess::class])->group(function () {
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

        return view('candidate.dashboard', compact('application'));
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
