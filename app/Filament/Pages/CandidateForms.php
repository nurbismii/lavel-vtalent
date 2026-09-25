<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CandidateForm;
use App\Models\CandidateFormVersion;
use App\Models\FormAccountDispatch;
use App\Models\FormIntake;
use App\Models\FormResponse;
use App\Models\Position;
use App\Models\RecruitmentPeriod;
use App\Models\User;
use App\Services\CandidateFormService;
use App\Services\FormAccountBulkService;
use App\Services\FormResponseExportService;
use App\Services\FormResponseQuery;
use Filament\Pages\Page;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateForms extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.candidate-forms';

    protected static ?string $title = 'Formulir Kandidat';

    protected static ?string $navigationLabel = 'Formulir kandidat';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    #[Locked]
    public ?int $formId = null;

    #[Locked]
    public int $formLock = 0;

    #[Locked]
    public ?int $responseId = null;

    #[Locked]
    public ?int $archiveId = null;

    #[Locked]
    public array $deletion = [];

    public string $tab = 'forms';

    public string $formTitle = '';

    public string $description = '';

    public array $fields = [];

    public bool $preview = false;

    public string $feedback = '';

    public string $search = '';

    public string $statusFilter = '';

    public string $linkedFilter = '';

    public string $intakeFilter = '';

    public string $positionFilter = '';

    public array $intake = ['candidate_form_version_id' => '', 'position_id' => '', 'recruitment_period_id' => '', 'starts_at' => '', 'deadline' => '', 'max_responses' => ''];

    public array $application = ['portfolio_deadline' => '', 'test_deadline' => '', 'task_label' => 'Tes teknis', 'instructions' => ''];

    public bool $confirmLink = false;

    #[Locked]
    public array $bulkResponseIds = [];

    #[Locked]
    public array $bulkApplication = [];

    public bool $confirmBulk = false;

    public string $revisionNote = '';

    public string $revisionDeadline = '';

    public function getHeading(): ?string
    {
        return null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return CandidateFormService::available() && $user?->active && $user->role === Role::Admin && ! $user->must_change_password;
    }

    public function mount(): void
    {
        $this->actor();
        if (request()->filled('response')) {
            $this->inspect((int) request('response'));
        }
        if (request()->filled('candidate')) {
            $this->tab = 'responses';
            $this->search = User::findOrFail((int) request('candidate'))->email;
        }
    }

    private function actor(): User
    {
        abort_unless(CandidateFormService::available(), 404);
        $actor = auth()->user();
        abort_unless($actor, 403);
        app(CandidateFormService::class)->admin($actor);

        return $actor;
    }

    public function navigate(string $tab): void
    {
        $this->actor();
        abort_unless(in_array($tab, ['forms', 'editor', 'intakes', 'responses'], true), 422);
        $this->tab = $tab;
        $this->cancelBulkAccounts();
        $this->cancelDelete();
        $this->reset('responseId', 'confirmLink', 'feedback');
        $this->resetValidation();
        $this->resetPage();
    }

    public function create(): void
    {
        $this->navigate('editor');
        $this->reset('formId', 'formLock', 'formTitle', 'description', 'fields', 'preview');
    }

    public function edit(int $id): void
    {
        $this->navigate('editor');
        $form = CandidateForm::findOrFail($id);
        $this->formId = $id;
        $this->formLock = $form->lock_version;
        $this->formTitle = $form->title;
        $this->description = $form->description ?? '';
        $this->fields = array_map(fn ($f) => [...$f, 'options_text' => implode("\n", $f['options'])], $form->fields);
        $this->preview = false;
    }

    public function duplicate(int $id): void
    {
        $this->edit($id);
        $this->formId = null;
        $this->formLock = 0;
        $this->formTitle .= ' (Salinan)';
        foreach ($this->fields as &$field) {
            $field['id'] = (string) Str::uuid();
        }
        $this->save();
    }

    public function addField(): void
    {
        $this->actor();
        if (count($this->fields) >= 100) {
            return;
        }
        $this->fields[] = ['id' => (string) Str::uuid(), 'type' => 'text', 'label' => '', 'help' => '', 'placeholder' => '', 'required' => false, 'options_text' => '', 'options' => [], 'max_length' => 255, 'min' => '', 'max' => '', 'max_mb' => 10, 'max_files' => 1, 'extensions' => ['pdf']];
    }

    public function moveField(int $index, int $direction): void
    {
        $this->actor();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $other = $index + $direction;
        if (isset($this->fields[$index], $this->fields[$other])) {
            [$this->fields[$index], $this->fields[$other]] = [$this->fields[$other], $this->fields[$index]];
        }
    }

    public function removeField(int $index): void
    {
        $this->actor();
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function duplicateField(int $index): void
    {
        $this->actor();
        abort_unless(isset($this->fields[$index]) && count($this->fields) < 100, 422);
        $field = $this->fields[$index];
        $field['id'] = (string) Str::uuid();
        $field['label'] .= ' (Salinan)';
        array_splice($this->fields, $index + 1, 0, [$field]);
    }

    private function schemaData(): array
    {
        $fields = array_map(function ($field) {
            $field['options'] = array_values(array_filter(array_map('trim', explode("\n", $field['options_text'] ?? '')), fn ($v) => $v !== ''));
            unset($field['options_text']);

            return $field;
        }, $this->fields);

        return ['title' => $this->formTitle, 'description' => $this->description, 'fields' => $fields];
    }

    public function showPreview(): void
    {
        $this->actor();
        app(CandidateFormService::class)->schema($this->schemaData());
        $this->preview = true;
    }

    public function save(): void
    {
        $form = app(CandidateFormService::class)->saveTemplate($this->actor(), $this->formId, $this->formLock, $this->schemaData());
        $this->formId = $form->id;
        $this->formLock = $form->lock_version;
        $this->feedback = 'Draf tersimpan. Tautan yang sudah dibagikan tetap memakai versi sebelumnya.';
    }

    public function publish(): void
    {
        $this->save();
        $version = app(CandidateFormService::class)->publish($this->actor(), CandidateForm::findOrFail($this->formId), $this->formLock);
        $this->formLock++;
        $this->intake['candidate_form_version_id'] = $version->id;
        $this->tab = 'intakes';
        $this->feedback = 'Versi '.$version->number.' diterbitkan. Buat tautan penerimaan di bawah.';
    }

    public function archive(int $id): void
    {
        $actor = $this->actor();
        abort_unless($this->archiveId === $id, 422);
        DB::transaction(function () use ($id, $actor) {
            $form = CandidateForm::lockForUpdate()->findOrFail($id);
            $form->update(['archived_at' => now(), 'lock_version' => $form->lock_version + 1]);
            AuditLog::record('form.archived', $form, $actor);
        });
        $this->feedback = 'Formulir diarsipkan. Respons dan dokumen tetap tersedia.';
        $this->archiveId = null;
    }

    public function confirmArchive(int $id): void
    {
        $this->actor();
        CandidateForm::findOrFail($id);
        $this->archiveId = $id;
    }

    public function cancelArchive(): void
    {
        $this->archiveId = null;
    }

    public function confirmDelete(string $type, int $id): void
    {
        $this->resetValidation();
        $this->deletion = app(CandidateFormService::class)->deletionSummary($this->actor(), $type, $id);
    }

    public function cancelDelete(): void
    {
        $this->deletion = [];
        $this->resetValidation('deletion');
    }

    public function deleteConfirmed(): void
    {
        $actor = $this->actor();
        abort_unless($this->deletion, 422);
        app(CandidateFormService::class)->deleteUnused($actor, $this->deletion['type'], $this->deletion['id'], $this->deletion);
        $this->feedback = $this->deletion['type'] === 'form'
            ? 'Formulir, seluruh versi, dan tautan kosongnya berhasil dihapus.'
            : 'Tautan penerimaan berhasil dihapus. Tautan publik dan verifikasi lama tidak dapat digunakan lagi.';
        $this->cancelDelete();
        $this->resetPage('templatesPage');
        $this->resetPage('intakesPage');
    }

    public function createIntake(): void
    {
        $intake = app(CandidateFormService::class)->createIntake($this->actor(), $this->intake);
        $this->feedback = 'Tautan penerimaan dibuat: '.route('forms.show', $intake->slug);
    }

    public function toggleIntake(int $id): void
    {
        $actor = $this->actor();
        DB::transaction(function () use ($actor, $id) {
            $intake = FormIntake::lockForUpdate()->findOrFail($id);
            $intake->update(['active' => ! $intake->active]);
            AuditLog::record('form.intake_toggled', $intake, $actor);
        });
    }

    public function inspect(int $id): void
    {
        $this->actor();
        FormResponse::whereNotNull('submitted_at')->findOrFail($id);
        $this->tab = 'responses';
        $this->responseId = $id;
        $this->reset('confirmLink', 'revisionNote', 'revisionDeadline', 'feedback');
        $this->resetValidation();
    }

    public function link(): void
    {
        $this->validate(['confirmLink' => 'accepted']);
        try {
            $response = app(CandidateFormService::class)->link($this->actor(), FormResponse::findOrFail($this->responseId), $this->application);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['link' => 'Akun atau lamaran baru saja dibuat oleh proses lain. Muat ulang lalu hubungkan kembali.']);
        }
        $this->confirmLink = false;
        $this->feedback = 'Respons dan dokumen berhasil terhubung. Data kandidat lama tidak ditimpa.';
        if ($response->activation_pending) {
            $this->sendAccountEmail();
        }
    }

    // Keep previously mounted Livewire pages compatible with the renamed action.
    public function sendActivation(): void
    {
        $this->sendAccountEmail();
    }

    public function sendAccountEmail(): void
    {
        $actor = $this->actor();
        $response = FormResponse::with('user')->findOrFail($this->responseId);
        abort_unless($response->user_id && $response->activation_pending && $response->user->active && $response->user->role === Role::Candidate, 422);
        try {
            $delivery = app(CandidateFormService::class)->sendCandidateAccount($actor, $response);
            $this->feedback = match ($delivery->status) {
                'sent' => 'Data terhubung. Email akun kandidat berisi akses masuk dan password sementara telah dikirim.',
                'failed', 'cancelled' => 'Data tetap terhubung, tetapi email akun kandidat belum berhasil dikirim. Periksa konfigurasi email lalu kirim ulang.',
                default => 'Data terhubung. Email akun kandidat berisi akses masuk dan password sementara telah masuk antrean pengiriman.',
            };
        } catch (ValidationException $exception) {
            $this->addError('accountEmail', $exception->validator->errors()->first());
        } catch (\Throwable $exception) {
            report($exception);
            $this->feedback = 'Data tetap terhubung, tetapi email akun kandidat gagal. Periksa konfigurasi email lalu kirim ulang.';
        }
    }

    public function requestRevision(): void
    {
        app(CandidateFormService::class)->revise($this->actor(), FormResponse::findOrFail($this->responseId), $this->revisionNote, $this->revisionDeadline);
        $this->feedback = 'Revisi dibuka. Kandidat dapat melanjutkan melalui akses email atau akun portal. Sampaikan catatan revisi kepada kandidat.';
    }

    public function responseFilters(): array
    {
        return ['search' => $this->search, 'status' => $this->statusFilter, 'linked' => $this->linkedFilter, 'intake' => $this->intakeFilter, 'position' => $this->positionFilter];
    }

    public function previewBulkAccounts(): void
    {
        $this->actor();
        abort_unless(FormAccountBulkService::available(), 404);
        $this->cancelBulkAccounts();
        $this->bulkApplication = app(FormAccountBulkService::class)->validateApplication($this->application);
        $ids = app(FormResponseQuery::class)->eligible($this->responseFilters())->orderBy('id')->limit(10001)->pluck('id')->all();
        if (! $ids || count($ids) > 10000) {
            throw ValidationException::withMessages(['bulk' => ! $ids ? 'Tidak ada respons final terverifikasi yang belum terhubung atau belum masuk antrean sesuai filter.' : 'Lebih dari 10.000 respons. Persempit filter posisi atau tautan penerimaan.']);
        }
        $this->bulkResponseIds = $ids;
    }

    public function cancelBulkAccounts(): void
    {
        $this->reset('bulkResponseIds', 'bulkApplication', 'confirmBulk');
    }

    public function sendBulkAccounts(): void
    {
        $actor = $this->actor();
        $this->validate(['confirmBulk' => 'accepted']);
        abort_unless($this->bulkResponseIds, 422);
        $count = app(FormAccountBulkService::class)->start($actor, $this->bulkResponseIds, $this->bulkApplication);
        $this->cancelBulkAccounts();
        $this->feedback = $count.' respons dimasukkan ke antrean pembuatan/penghubungan akun dan email. Pantau status pengiriman di bawah.';
    }

    public function retryBulkAccount(int $id): void
    {
        app(FormAccountBulkService::class)->retry($this->actor(), $id, $this->application);
        $this->feedback = 'Proses kandidat dimasukkan kembali ke antrean.';
    }

    public function exportResponses(): StreamedResponse
    {
        $actor = $this->actor();
        $filters = $this->responseFilters();

        return response()->streamDownload(function () use ($actor, $filters) {
            app(FormResponseExportService::class)->write($actor, $filters, 'php://output');
        }, 'respons-formulir-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }

    public function updatedSearch(): void
    {
        $this->cancelBulkAccounts();
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->cancelBulkAccounts();
        $this->resetPage();
    }

    public function updatedLinkedFilter(): void
    {
        $this->cancelBulkAccounts();
        $this->resetPage();
    }

    public function updatedIntakeFilter(): void
    {
        $this->cancelBulkAccounts();
        $this->resetPage();
    }

    public function updatedPositionFilter(): void
    {
        $this->cancelBulkAccounts();
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $this->actor();
        $query = app(FormResponseQuery::class)->filtered($this->responseFilters())->with('intake.version', 'intake.position', 'intake.period', 'latestRevision');
        $bulkAvailable = FormAccountBulkService::available();

        return [
            'responseStats' => $this->tab === 'responses' && ! $this->responseId
                ? (clone $query)->toBase()->selectRaw('COUNT(*) as total, SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) as submitted, SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as linked')->first()
                : null,
            'bulkAvailable' => $bulkAvailable,
            'bulkPreview' => $this->bulkResponseIds ? FormResponse::whereKey(array_slice($this->bulkResponseIds, 0, 20))->with('intake.position', 'intake.period')->orderBy('id')->get() : collect(),
            'dispatches' => $bulkAvailable ? FormAccountDispatch::with('response', 'delivery')->latest()->paginate(20, ['*'], 'dispatchesPage') : null,
            'templates' => CandidateForm::withCount('versions')->latest()->paginate(15, ['*'], 'templatesPage'),
            'versions' => CandidateFormVersion::whereHas('form', fn ($q) => $q->whereNull('archived_at'))->latest()->get(),
            'positions' => Position::where('active', true)->get(), 'periods' => RecruitmentPeriod::where('active', true)->get(),
            'intakes' => FormIntake::with('version.form', 'position', 'period')->withCount(['responses', 'responses as submitted_count' => fn ($q) => $q->whereNotNull('submitted_at')])->latest()->paginate(15, ['*'], 'intakesPage'),
            'responses' => $query->latest()->paginate(20),
            'selectedResponse' => $this->responseId ? FormResponse::with('intake.version', 'intake.position', 'intake.period', 'revisions', 'documents', 'user', 'application')->whereNotNull('submitted_at')->findOrFail($this->responseId) : null,
            'previewFields' => $this->preview ? $this->schemaData()['fields'] : [],
        ];
    }
}
