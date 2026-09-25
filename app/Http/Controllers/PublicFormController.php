<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Responses\FormRateLimitResponse;
use App\Jobs\SendFormAccess;
use App\Models\AuditLog;
use App\Models\FormAccessToken;
use App\Models\FormDocument;
use App\Models\FormIntake;
use App\Models\FormResponse;
use App\Services\CandidateFormService;
use App\Services\FormDocumentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicFormController extends Controller
{
    public function __construct(private CandidateFormService $forms) {}

    public function show(Request $request, FormIntake $intake): mixed
    {
        return view('forms.public', ['intake' => $intake->load('version', 'position', 'period'), 'response' => null, 'preview' => false, 'pending' => $request->session()->get('form_unverified.'.$intake->id)]);
    }

    public function access(Request $request, FormIntake $intake): mixed
    {
        $request->validate(['email' => 'required|email|max:255']);
        $key = 'form-access-request:'.hash('sha256', Str::lower(trim($request->email)));

        try {
            return Cache::lock($key, 30)->block(5, fn () => $this->scheduleAccess($request, $intake));
        } catch (LockTimeoutException) {
            return FormRateLimitResponse::make($request, ['Retry-After' => 5]);
        }
    }

    private function scheduleAccess(Request $request, FormIntake $intake): mixed
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'name' => 'nullable|string|max:255', 'answers' => 'sometimes|array|max:100', 'website' => 'nullable|max:0', 'resend' => 'sometimes|boolean']);
        $email = Str::lower(trim($data['email']));
        $draft = $this->forms->validateAnswers($intake->version->fields, $data, false);
        $existing = $intake->responses()->where('email', $email)->first();
        if ($existing && $this->forms->hasPublicAccess($request, $existing)) {
            return redirect()->route('forms.response', $existing->reference, 303);
        }
        $request->session()->put('form_unverified.'.$intake->id, ['email' => $email, 'data' => $draft]);
        $request->session()->put('form_waiting.'.$intake->id, $email);
        $activeToken = FormAccessToken::where('form_intake_id', $intake->id)->where('email', $email)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if ($activeToken && ! $request->boolean('resend')) {
            return redirect()->route('forms.waiting', $intake->slug, 303);
        }
        $key = 'form-email:'.hash('sha256', $email);
        $cooldownKey = $key.':resend';
        $retryAfter = max(
            RateLimiter::tooManyAttempts($key, config('candidate_forms.email_max_per_hour')) ? RateLimiter::availableIn($key) : 0,
            RateLimiter::tooManyAttempts($cooldownKey, 1) ? RateLimiter::availableIn($cooldownKey) : 0,
        );
        if ($retryAfter > 0) {
            $request->flashExcept('_token', 'website');

            return FormRateLimitResponse::make($request, ['Retry-After' => $retryAfter]);
        }
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($cooldownKey, config('candidate_forms.email_resend_seconds'));
        if (! $intake->open() && ! $existing) {
            return redirect()->route('forms.waiting', $intake->slug, 303);
        }
        if ($existing?->user_id) {
            return redirect()->route('forms.waiting', $intake->slug, 303);
        }
        $raw = Str::random(64);
        $token = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => $email, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(config('candidate_forms.access_minutes'))]);
        try {
            SendFormAccess::dispatch($token->id, $raw);
        } catch (\Throwable $exception) {
            report($exception);
            $token->delete();
            RateLimiter::clear($cooldownKey);
            RateLimiter::decrement($key);

            return back()->withErrors(['email' => 'Email belum berhasil dijadwalkan. Coba lagi atau hubungi HR.'])->withInput($request->except('website'));
        }

        return redirect()->route('forms.waiting', $intake->slug, 303);
    }

    public function waiting(Request $request, FormIntake $intake): mixed
    {
        $email = $request->session()->get('form_waiting.'.$intake->id);
        if (! $email) {
            return redirect()->route('forms.show', $intake->slug);
        }
        $response = $intake->responses()->where('email', $email)->first();
        if ($response && $this->forms->hasPublicAccess($request, $response)) {
            return redirect()->route('forms.response', $response->reference);
        }
        $key = 'form-email:'.hash('sha256', $email);
        $retryAfter = max(RateLimiter::availableIn($key.':resend'), RateLimiter::tooManyAttempts($key, config('candidate_forms.email_max_per_hour')) ? RateLimiter::availableIn($key) : 0);

        return view('forms.waiting', compact('intake', 'email', 'retryAfter'));
    }

    public function resend(Request $request, FormIntake $intake): mixed
    {
        $pending = $request->session()->get('form_unverified.'.$intake->id);
        if (! $pending) {
            return redirect()->route('forms.show', $intake->slug);
        }
        $request->merge([...$pending['data'], 'email' => $pending['email'], 'resend' => true]);

        return $this->access($request, $intake);
    }

    public function verify(string $token): mixed
    {
        $record = FormAccessToken::where('token_hash', hash('sha256', $token))->first();
        abort_unless($record && ! $record->used_at && $record->expires_at->gt(now()), 410, 'Tautan kedaluwarsa atau sudah dipakai. Minta tautan baru dari formulir.');

        return view('forms.verify', compact('token'));
    }

    public function consume(Request $request, string $token): mixed
    {
        $response = $this->forms->consumeToken($token);
        if ($response->user_id) {
            return redirect()->route('login')->with('status', 'Formulir sudah terhubung. Masuk ke portal untuk melanjutkan.');
        }
        $request->session()->regenerate();
        $request->session()->put('form_access.'.$response->id, ['generation' => $response->access_generation, 'expires' => now()->addHours(config('candidate_forms.session_hours'))->timestamp]);
        $draft = $request->session()->pull('form_unverified.'.$response->form_intake_id);
        if ($response->lock_version === 0 && $response->editable() && ($draft['email'] ?? null) === $response->email) {
            $this->forms->save($response, 0, $draft['data']);
        }

        return redirect()->route('forms.response', $response->reference)->with('status', 'Email terverifikasi. Anda dapat mengunggah dokumen dan menyimpan draf.');
    }

    public function response(Request $request, FormResponse $response): mixed
    {
        abort_unless($this->forms->canRead($request, $response), 404);
        if ($request->user()?->role === Role::Admin && ! $this->forms->hasPublicAccess($request, $response)) {
            return redirect()->route('filament.admin.pages.candidate-forms', ['response' => $response->id]);
        }

        return view('forms.public', ['intake' => $response->intake->load('version', 'position', 'period'), 'response' => $response->load('documents'), 'preview' => false]);
    }

    public function save(Request $request, FormResponse $response): mixed
    {
        $this->forms->authorizeWrite($request, $response);
        $data = $request->validate(['lock_version' => 'required|integer|min:0', 'name' => 'nullable|string|max:255', 'answers' => 'sometimes|array|max:100', 'consent' => 'nullable', 'action' => 'required|in:save,review']);
        if ($data['action'] === 'review') {
            $this->forms->validateAnswers($response->intake->version->fields, $data, true);
            $this->forms->validateDocuments($response);
        }
        $response = $this->forms->save($response, (int) $data['lock_version'], $data);
        if ($data['action'] === 'review') {
            $request->session()->put('form_review.'.$response->id, $response->lock_version);

            return view('forms.review', ['response' => $response->load('documents'), 'intake' => $response->intake]);
        }

        return redirect()->route('forms.response', $response->reference)->with('status', 'Draf berhasil disimpan. Belum dikirim final.');
    }

    public function submit(Request $request, FormResponse $response): mixed
    {
        abort_unless($this->forms->canRead($request, $response), 404);
        abort_if($request->user()?->role === Role::Admin && ! $this->forms->hasPublicAccess($request, $response), 403);
        if ($response->status === 'submitted') {
            return redirect()->route('forms.response', $response->reference);
        }
        $this->forms->authorizeWrite($request, $response);
        $data = $request->validate(['lock_version' => 'required|integer', 'consent' => 'accepted']);
        abort_unless($request->session()->get('form_review.'.$response->id) === (int) $data['lock_version'], 422, 'Tinjau jawaban kembali sebelum mengirim.');
        $this->forms->save($response, (int) $data['lock_version'], ['name' => $response->name, 'answers' => $response->answers, 'consent' => true], true);
        $request->session()->forget('form_review.'.$response->id);

        return redirect()->route('forms.response', $response->reference)->with('status', 'Jawaban berhasil dikirim. Simpan nomor referensi Anda.');
    }

    public function upload(Request $request, FormResponse $response): mixed
    {
        $this->forms->authorizeWrite($request, $response);
        $request->validate(['field_id' => 'required|uuid', 'upload' => 'required|file']);
        try {
            app(FormDocumentService::class)->upload($response, $request->field_id, $request->file('upload'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unggahan gagal disimpan. Coba kembali atau hubungi HR.'], 503);
            }

            return back()->withErrors(['upload' => 'Unggahan gagal disimpan. Coba kembali atau hubungi HR.']);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'File tersimpan.']);
        }

        return back()->with('status', 'File tersimpan. Periksa status pemeriksaan sebelum mengirim final.');
    }

    public function documentAction(Request $request, FormDocument $document): mixed
    {
        $this->forms->authorizeWrite($request, $document->response);
        $request->validate(['action' => 'required|in:remove,retry']);
        $service = app(FormDocumentService::class);
        $request->action === 'remove' ? $service->remove($document) : $service->retry($document);

        return back()->with('status', 'Perubahan dokumen berhasil disimpan.');
    }

    public function download(Request $request, FormDocument $document): mixed
    {
        $response = $document->response;
        abort_unless($this->forms->canRead($request, $response) && $document->available(), 404);
        if ($request->user()?->role === Role::Admin && ! $this->forms->hasPublicAccess($request, $response)) {
            abort_unless($response->revisions()->get()->contains(fn ($revision) => in_array($document->id, $revision->document_ids, true)), 404);
            AuditLog::record($request->routeIs('forms.document.preview') ? 'form.document_previewed' : 'form.document_downloaded', $document, $request->user());
        }
        abort_unless(Storage::disk('private')->exists($document->path), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
        if ($request->routeIs('forms.document.preview')) {
            $mime = Storage::disk('private')->mimeType($document->path);
            if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                return response()->view('forms.document-preview', compact('document'), 200, $headers);
            }
            $headers['Content-Type'] = $mime;
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; frame-ancestors 'self'";

            return Storage::disk('private')->response($document->path, $document->original_name, $headers, 'inline');
        }

        return Storage::disk('private')->download($document->path, $document->original_name, $headers);
    }

    public function mine(Request $request): mixed
    {
        abort_unless($request->user()->role === Role::Candidate, 403);
        $responses = FormResponse::where('user_id', $request->user()->id)->whereHas('application', fn ($q) => $q->whereNull('purged_at'))->with('intake.version.form', 'intake.position', 'intake.period', 'application')->latest()->paginate(15);

        return view('candidate.forms', compact('responses'));
    }
}
