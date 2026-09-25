<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CandidateForm;
use App\Models\CandidateFormVersion;
use App\Models\FormAccessToken;
use App\Models\FormDocument;
use App\Models\FormIntake;
use App\Models\FormResponse;
use App\Models\RecruitmentApplication;
use App\Models\UploadedFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CandidateFormService
{
    public static function available(): bool
    {
        return config('candidate_forms.enabled') && Schema::hasTable('form_access_tokens');
    }

    public const TYPES = ['text' => 'Teks pendek', 'textarea' => 'Teks panjang', 'email' => 'Email', 'phone' => 'Telepon', 'number' => 'Angka', 'date' => 'Tanggal', 'radio' => 'Pilihan tunggal', 'select' => 'Dropdown', 'checkbox' => 'Multipilihan', 'url' => 'URL', 'file' => 'File', 'consent' => 'Persetujuan', 'section' => 'Bagian'];

    public function admin(User $actor): void
    {
        abort_unless($actor->active && $actor->role === Role::Admin && ! $actor->must_change_password && $actor->getAppAuthenticationSecret(), 403);
    }

    public function schema(array $data): array
    {
        $valid = Validator::make($data, [
            'title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000',
            'fields' => 'present|array|max:100', 'fields.*.id' => 'required|uuid|distinct',
            'fields.*.type' => ['required', Rule::in(array_keys(self::TYPES))],
            'fields.*.label' => 'required|string|max:255', 'fields.*.help' => 'nullable|string|max:1000',
            'fields.*.placeholder' => 'nullable|string|max:255', 'fields.*.required' => 'required|boolean',
            'fields.*.options' => 'present|array|max:100', 'fields.*.options.*' => 'required|string|max:255',
            'fields.*.max_length' => 'nullable|integer|min:1|max:10000',
            'fields.*.min' => 'nullable|string|max:30', 'fields.*.max' => 'nullable|string|max:30',
            'fields.*.max_mb' => 'nullable|integer|min:1|max:'.config('candidate_forms.max_mb'),
            'fields.*.max_files' => 'nullable|integer|min:1|max:'.config('candidate_forms.max_files'),
            'fields.*.extensions' => 'present|array',
            'fields.*.extensions.*' => [Rule::in(config('candidate_forms.extensions'))],
        ])->validate();
        foreach ($valid['fields'] as $i => &$field) {
            $field = array_replace(['help' => '', 'placeholder' => '', 'max_length' => null, 'min' => '', 'max' => '', 'max_mb' => null, 'max_files' => null], $field);
            if (in_array($field['type'], ['select', 'radio', 'checkbox'], true)) {
                if (! count($field['options']) || count(array_unique($field['options'])) !== count($field['options'])) {
                    throw ValidationException::withMessages(["fields.$i.options" => 'Pilihan harus terisi dan tidak boleh duplikat.']);
                }
            }
            if ($field['type'] === 'file' && (! count($field['extensions']) || empty($field['max_mb']) || empty($field['max_files']))) {
                throw ValidationException::withMessages(["fields.$i.extensions" => 'Atur format, ukuran, dan jumlah file.']);
            }
            if (in_array($field['type'], ['number', 'date', 'checkbox'], true)) {
                $bounds = array_filter(['min' => $field['min'] ?? null, 'max' => $field['max'] ?? null], fn ($v) => $v !== null && $v !== '');
                $rule = $field['type'] === 'date' ? 'date_format:Y-m-d' : ($field['type'] === 'checkbox' ? 'integer|min:0|max:100' : 'numeric');
                Validator::make($bounds, ['min' => $rule, 'max' => $rule])->validate();
                if (isset($bounds['min'], $bounds['max']) && $bounds['min'] > $bounds['max']) {
                    throw ValidationException::withMessages(["fields.$i.max" => 'Batas maksimum harus lebih besar atau sama dengan minimum.']);
                }
                if ($field['type'] === 'checkbox' && (int) ($bounds['min'] ?? 0) > count($field['options'])) {
                    throw ValidationException::withMessages(["fields.$i.min" => 'Minimum pilihan melebihi jumlah opsi.']);
                }
            }
        }
        unset($field);

        return $valid;
    }

    public function saveTemplate(User $actor, ?int $id, int $expected, array $data): CandidateForm
    {
        $this->admin($actor);
        $data = $this->schema($data);

        return DB::transaction(function () use ($actor, $id, $expected, $data) {
            $form = $id ? CandidateForm::lockForUpdate()->findOrFail($id) : new CandidateForm;
            if ($id && ($form->lock_version !== $expected || $form->archived_at)) {
                throw ValidationException::withMessages(['title' => 'Formulir berubah atau diarsipkan. Muat ulang sebelum menyimpan.']);
            }
            $form->fill($data);
            $form->lock_version = ($form->lock_version ?? 0) + 1;
            $form->save();
            AuditLog::record('form.saved', $form, $actor);

            return $form;
        }, 5);
    }

    public function publish(User $actor, CandidateForm $form, int $expected): CandidateFormVersion
    {
        $this->admin($actor);

        return DB::transaction(function () use ($actor, $form, $expected) {
            $form = CandidateForm::lockForUpdate()->findOrFail($form->id);
            if ($form->archived_at || $form->lock_version !== $expected) {
                throw ValidationException::withMessages(['title' => 'Formulir berubah atau diarsipkan. Muat ulang.']);
            }
            $this->schema($form->only('title', 'description', 'fields'));
            $version = $form->versions()->create([...$form->only('title', 'description', 'fields'), 'number' => ($form->versions()->max('number') ?? 0) + 1]);
            $form->increment('lock_version');
            AuditLog::record('form.published', $version, $actor);

            return $version;
        }, 5);
    }

    public function createIntake(User $actor, array $data): FormIntake
    {
        $this->admin($actor);
        $limit = $data['max_responses'] ?? null;
        if (is_string($limit)) {
            $limit = trim($limit);
        }
        $data['max_responses'] = $limit === '' ? null : $limit;
        $data = Validator::make($data, [
            'candidate_form_version_id' => 'required|exists:candidate_form_versions,id',
            'position_id' => 'required|exists:positions,id', 'recruitment_period_id' => 'required|exists:recruitment_periods,id',
            'starts_at' => 'nullable|date', 'deadline' => 'nullable|date', 'max_responses' => 'nullable|integer|min:1|max:100000',
        ])->validate();
        $data['max_responses'] = $data['max_responses'] === null ? null : (int) $data['max_responses'];
        foreach (['starts_at', 'deadline'] as $key) {
            $data[$key] = empty($data[$key]) ? null : Carbon::parse($data[$key], AppSetting::valueFor('timezone'))->utc();
        }
        if ($data['deadline'] && $data['deadline']->lte(now())) {
            throw ValidationException::withMessages(['deadline' => 'Tenggat harus berada di masa depan pada zona waktu portal.']);
        }
        if ($data['starts_at'] && $data['deadline'] && $data['starts_at']->gte($data['deadline'])) {
            throw ValidationException::withMessages(['deadline' => 'Tenggat harus setelah waktu mulai.']);
        }

        return DB::transaction(function () use ($actor, $data) {
            $version = CandidateFormVersion::findOrFail($data['candidate_form_version_id']);
            abort_if(CandidateForm::lockForUpdate()->findOrFail($version->candidate_form_id)->archived_at, 422, 'Formulir diarsipkan.');
            $intake = FormIntake::create([...$data, 'slug' => (string) Str::uuid()]);
            abort_unless($intake->position->active && $intake->period->active, 422, 'Posisi dan periode harus aktif.');
            AuditLog::record('form.intake_created', $intake, $actor);

            return $intake;
        }, 5);
    }

    public function deletionSummary(User $actor, string $type, int $id): array
    {
        $this->admin($actor);
        abort_unless(in_array($type, ['form', 'intake'], true), 422);
        $target = $type === 'form' ? CandidateForm::findOrFail($id) : FormIntake::with('version', 'position', 'period')->findOrFail($id);
        $intakeIds = $type === 'form'
            ? FormIntake::whereIn('candidate_form_version_id', $target->versions()->select('id'))->orderBy('id')->pluck('id')->all()
            : [$id];
        $responses = FormResponse::whereIn('form_intake_id', $intakeIds);

        return [
            'type' => $type, 'id' => $id,
            'title' => $type === 'form' ? $target->title : $target->version->title.' · '.$target->position->name.' · '.$target->period->name,
            'intake_ids' => $intakeIds,
            'versions' => $type === 'form' ? $target->versions()->count() : 1,
            'responses' => (clone $responses)->count(),
            'drafts' => (clone $responses)->whereNull('submitted_at')->count(),
            'submitted' => (clone $responses)->whereNotNull('submitted_at')->count(),
            'linked' => (clone $responses)->whereNotNull('user_id')->count(),
            'documents' => FormDocument::whereIn('form_response_id', (clone $responses)->select('id'))->count(),
            'pending_tokens' => FormAccessToken::whereIn('form_intake_id', $intakeIds)->whereNull('used_at')->where('expires_at', '>', now())->count(),
        ];
    }

    public function deleteUnused(User $actor, string $type, int $id, array $confirmed): void
    {
        $this->admin($actor);
        abort_unless(in_array($type, ['form', 'intake'], true), 422);
        DB::transaction(function () use ($actor, $type, $id, $confirmed) {
            if ($type === 'form') {
                $target = CandidateForm::lockForUpdate()->findOrFail($id);
                $intakes = FormIntake::whereIn('candidate_form_version_id', $target->versions()->select('id'))->orderBy('id')->lockForUpdate()->get();
            } else {
                $target = FormIntake::lockForUpdate()->findOrFail($id);
                $intakes = new Collection([$target]);
            }
            $summary = $this->deletionSummary($actor, $type, $id);
            if ($summary['responses'] > 0) {
                throw ValidationException::withMessages(['deletion' => 'Sudah ada pendaftar. Hapus permanen diblokir agar respons dan dokumen tidak hilang. Arsipkan formulir atau tutup penerimaan.']);
            }
            if ($summary !== $confirmed) {
                throw ValidationException::withMessages(['deletion' => 'Data berubah sejak konfirmasi dibuka. Batalkan lalu buka konfirmasi kembali untuk melihat dampak terbaru.']);
            }
            AuditLog::record($type === 'form' ? 'form.deleted' : 'form.intake_deleted', $target, $actor, null, $summary);
            FormAccessToken::whereIn('form_intake_id', $intakes->modelKeys())->delete();
            foreach ($intakes as $intake) {
                $intake->delete();
            }
            if ($type === 'form') {
                $target->versions()->delete();
                $target->delete();
            }
        }, 5);
    }

    public function consumeToken(string $raw): FormResponse
    {
        return DB::transaction(function () use ($raw) {
            $token = FormAccessToken::where('token_hash', hash('sha256', $raw))->lockForUpdate()->first();
            abort_unless($token && ! $token->used_at && $token->expires_at->gt(now()), 410, 'Tautan sudah dipakai atau kedaluwarsa. Minta tautan baru.');
            $intake = FormIntake::lockForUpdate()->findOrFail($token->form_intake_id);
            $response = $intake->responses()->where('email', $token->email)->lockForUpdate()->first();
            if (! $response) {
                abort_unless($intake->open(), 410, 'Penerimaan formulir sudah ditutup.');
                $response = $intake->responses()->create(['reference' => (string) Str::uuid(), 'email' => $token->email, 'email_verified_at' => now(), 'answers' => []]);
            }
            $token->update(['used_at' => now()]);

            return $response;
        }, 5);
    }

    public function canRead(Request $request, FormResponse $response): bool
    {
        if ($this->hasPublicAccess($request, $response)) {
            return true;
        }
        $user = $request->user();
        if ($user && $user->active && ! $user->must_change_password && $request->session()->get('portal_session_version') === $user->session_version) {
            if ($user->role === Role::Admin && $user->getAppAuthenticationSecret()) {
                return $response->submitted_at !== null;
            }
            if ($user->role === Role::Candidate && $response->user_id === $user->id && ! $response->application?->purged_at) {
                return true;
            }
        }

        return false;
    }

    public function hasPublicAccess(Request $request, FormResponse $response): bool
    {
        if ($response->user_id) {
            return false;
        }
        $grant = $request->session()->get('form_access.'.$response->id);

        return is_array($grant) && ($grant['generation'] ?? null) === $response->access_generation && ($grant['expires'] ?? 0) > now()->timestamp;
    }

    public function authorizeWrite(Request $request, FormResponse $response): void
    {
        abort_unless($this->canRead($request, $response), 404);
        abort_if($request->user()?->role === Role::Admin && ! $this->hasPublicAccess($request, $response), 403);
        abort_unless($response->editable(), 422, 'Pengisian terkunci atau tenggat sudah berakhir.');
    }

    public function validateAnswers(array $fields, array $data, bool $final): array
    {
        $data['answers'] ??= [];
        $known = collect($fields)->reject(fn ($f) => in_array($f['type'], ['section', 'file'], true))->pluck('id')->all();
        if (array_diff(array_keys($data['answers']), $known)) {
            throw ValidationException::withMessages(['answers' => 'Terdapat pertanyaan yang tidak dikenal. Muat ulang formulir.']);
        }
        $rules = ['name' => [$final ? 'required' : 'nullable', 'string', 'max:255'], 'answers' => 'array', 'consent' => [$final ? 'accepted' : 'nullable']];
        $labels = ['name' => 'Nama lengkap', 'consent' => 'Persetujuan pemrosesan data'];
        foreach ($fields as $field) {
            $id = $field['id'];
            $type = $field['type'];
            if (in_array($type, ['section', 'file'], true)) {
                continue;
            }
            $key = 'answers.'.$id;
            $labels[$key] = $field['label'];
            $required = $final && $field['required'];
            $rules[$key] = [$required ? 'required' : 'nullable'];
            if ($type === 'consent') {
                $rules[$key][] = $required ? 'accepted' : 'boolean';
            } elseif ($type === 'checkbox') {
                $rules[$key][] = 'array';
                $rules[$key][] = 'max:'.($field['max'] !== '' && $field['max'] !== null ? $field['max'] : count($field['options']));
                if ($final && ! empty($field['min']) && ($required || ! empty($data['answers'][$id]))) {
                    $rules[$key][] = 'min:'.$field['min'];
                }
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in($field['options'])];
            } elseif (in_array($type, ['radio', 'select'], true)) {
                $rules[$key][] = 'string';
                $rules[$key][] = Rule::in($field['options']);
            } elseif ($type === 'number') {
                $rules[$key][] = 'numeric';
                foreach (['min', 'max'] as $bound) {
                    if (isset($field[$bound]) && $field[$bound] !== '') {
                        $rules[$key][] = $bound.':'.$field[$bound];
                    }
                }
            } elseif ($type === 'date') {
                $rules[$key][] = 'date_format:Y-m-d';
                if (! empty($field['min'])) {
                    $rules[$key][] = 'after_or_equal:'.$field['min'];
                }
                if (! empty($field['max'])) {
                    $rules[$key][] = 'before_or_equal:'.$field['max'];
                }
            } else {
                $rules[$key][] = 'string';
                $rules[$key][] = 'max:'.($field['max_length'] ?: ($type === 'textarea' ? 5000 : 255));
                if ($type === 'email') {
                    $rules[$key][] = 'email';
                }
                if ($type === 'url') {
                    $rules[$key][] = 'url:http,https';
                }
                if ($type === 'phone') {
                    $rules[$key][] = 'regex:/^[+0-9() .-]+$/';
                }
            }
        }

        return Validator::make($data, $rules, ['required' => ':attribute wajib diisi.', 'accepted' => ':attribute wajib disetujui.'], $labels)->validate();
    }

    public function validateDocuments(FormResponse $response): void
    {
        $documents = $response->documents()->where('selected', true)->get();
        $errors = [];
        foreach ($response->intake->version->fields as $field) {
            if ($field['type'] !== 'file') {
                continue;
            }
            $files = $documents->where('field_id', $field['id']);
            if (($field['required'] && $files->isEmpty()) || $files->count() > $field['max_files'] || $files->contains(fn ($file) => ! $file->available())) {
                $errors['files.'.$field['id']] = 'Lengkapi '.$field['label'].' dan tunggu pemeriksaan file selesai.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function save(FormResponse $response, int $expected, array $data, bool $final = false): FormResponse
    {
        return DB::transaction(function () use ($response, $expected, $data, $final) {
            $intake = FormIntake::lockForUpdate()->findOrFail($response->form_intake_id);
            $response = FormResponse::lockForUpdate()->findOrFail($response->id);
            if ($final && $response->status === 'submitted') {
                return $response;
            }
            abort_unless($response->editable(), 422, 'Pengisian terkunci atau tenggat sudah berakhir.');
            if ($response->lock_version !== $expected) {
                throw ValidationException::withMessages(['conflict' => 'Data berubah di tab lain. Salin perubahan Anda lalu muat ulang sebelum menyimpan.']);
            }
            $valid = $this->validateAnswers($intake->version->fields, $data, $final);
            $documents = $response->documents()->where('selected', true)->get();
            if ($final) {
                if (! $response->submitted_at && $intake->max_responses && $intake->responses()->whereNotNull('submitted_at')->count() >= $intake->max_responses) {
                    throw ValidationException::withMessages(['quota' => 'Batas penerimaan respons sudah tercapai.']);
                }
                $this->validateDocuments($response);
            }
            $response->fill(['name' => $valid['name'] ?? '', 'answers' => $valid['answers'] ?? [], 'lock_version' => $expected + 1]);
            if ($final) {
                $response->status = 'submitted';
                $response->submitted_at = now();
                $response->revisions()->create(['number' => ($response->revisions()->max('number') ?? 0) + 1, 'name' => $response->name, 'answers' => $response->answers, 'document_ids' => $documents->modelKeys(), 'submitted_at' => now()]);
            }
            $response->save();
            if ($final) {
                AuditLog::record('form.submitted', $response, null, null, ['revision' => $response->revisions()->count()]);
            }

            return $response;
        }, 5);
    }

    public function revise(User $actor, FormResponse $response, string $note, string $deadline): void
    {
        $this->admin($actor);
        Validator::make(['note' => $note, 'deadline' => $deadline], ['note' => 'required|string|min:5|max:2000', 'deadline' => 'required|date'])->validate();
        if (Carbon::parse($deadline, AppSetting::valueFor('timezone'))->lte(now())) {
            throw ValidationException::withMessages(['deadline' => 'Tenggat revisi harus berada di masa depan pada zona waktu portal.']);
        }
        DB::transaction(function () use ($actor, $response, $note, $deadline) {
            $response = FormResponse::lockForUpdate()->findOrFail($response->id);
            abort_unless($response->status === 'submitted', 422);
            $response->update(['status' => 'revision', 'revision_note' => $note, 'revision_deadline' => Carbon::parse($deadline, AppSetting::valueFor('timezone'))->utc(), 'lock_version' => $response->lock_version + 1]);
            AuditLog::record('form.revision_requested', $response, $actor, $note);
        }, 5);
    }

    public function link(User $actor, FormResponse $response, array $applicationData): FormResponse
    {
        $this->admin($actor);

        return DB::transaction(function () use ($actor, $response, $applicationData) {
            $response = FormResponse::lockForUpdate()->findOrFail($response->id);
            if ($response->user_id) {
                return $response;
            }
            abort_unless($response->status === 'submitted' && $response->email_verified_at, 422);
            $intake = $response->intake;
            $user = User::whereRaw('LOWER(email) = ?', [$response->email])->lockForUpdate()->first();
            if ($user && ($user->role !== Role::Candidate || ! $user->active)) {
                throw ValidationException::withMessages(['link' => 'Email ini milik akun yang tidak dapat digunakan sebagai kandidat.']);
            }
            $application = $user ? RecruitmentApplication::where('active_user_id', $user->id)->lockForUpdate()->first() : null;
            if ($application && ($application->position_id !== $intake->position_id || $application->recruitment_period_id !== $intake->recruitment_period_id)) {
                throw ValidationException::withMessages(['link' => 'Kandidat mempunyai lamaran aktif pada posisi/periode berbeda. Selesaikan konflik melalui pengelolaan lamaran.']);
            }
            $newAccount = ! $user;
            if (! $application) {
                $result = app(RecruitmentService::class)->create($actor, [...$applicationData, 'name' => $response->name, 'email' => $response->email, 'position_id' => $intake->position_id, 'recruitment_period_id' => $intake->recruitment_period_id]);
                $application = $result['application'];
                $user = $application->user;
            }
            $used = UploadedFile::where('recruitment_application_id', $application->id)->sum('size')
                + FormDocument::whereHas('response', fn ($q) => $q->where('recruitment_application_id', $application->id)->orWhere('id', $response->id))->sum('size');
            if ($used > AppSetting::valueFor('quota_mb') * 1024 * 1024) {
                throw ValidationException::withMessages(['link' => 'Total dokumen melebihi kuota lamaran. Sesuaikan kuota sebelum menghubungkan.']);
            }
            $response->update(['user_id' => $user->id, 'recruitment_application_id' => $application->id, 'linked_at' => now(), 'activation_pending' => $newAccount, 'access_generation' => $response->access_generation + 1]);
            FormAccessToken::where('form_intake_id', $intake->id)->where('email', $response->email)->whereNull('used_at')->update(['used_at' => now()]);
            AuditLog::record('form.linked', $response, $actor, null, ['user_id' => $user->id, 'application_id' => $application->id]);

            return $response;
        }, 5);
    }

    public function sendCandidateAccount(User $actor, FormResponse $response): AccessDelivery
    {
        $this->admin($actor);
        $delivery = DB::transaction(function () use ($actor, $response) {
            $response = FormResponse::lockForUpdate()->findOrFail($response->id);
            abort_unless($response->user_id && $response->activation_pending, 422);
            $user = User::lockForUpdate()->findOrFail($response->user_id);
            abort_unless($user->active && $user->role === Role::Candidate, 422);
            if (! $user->must_change_password) {
                throw ValidationException::withMessages(['accountEmail' => 'Kandidat sudah menetapkan password. Akses akun tidak diubah.']);
            }
            app(RecruitmentToolsService::class)->sendAccess($actor, [$user->id], 'Akun kandidat Anda telah dibuat. Data dan dokumen formulir sudah terhubung. Silakan masuk ke portal menggunakan akses berikut dan ganti password pada login pertama.');
            $delivery = AccessDelivery::where('user_id', $user->id)->latest('id')->firstOrFail();
            AuditLog::record('form.account_email_queued', $response, $actor, null, ['delivery_id' => $delivery->id]);

            return $delivery;
        }, 5);

        return $delivery->fresh();
    }
}
