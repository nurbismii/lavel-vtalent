<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CandidateImportService;
use App\Services\RecruitmentService;
use App\Services\SubmissionService;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Recruitment extends Page
{
    use WithFileUploads;
    use WithPagination;

    public $importFile;

    public function updatedImportFile(): void
    {
        $this->authorizeAdmin();
        $this->resetValidation('importFile');
        $this->feedback = '';
    }

    public function downloadCandidateTemplate(): StreamedResponse
    {
        $this->authorizeAdmin();

        return response()->streamDownload(function () {
            app(CandidateImportService::class)->writeTemplate('php://output');
        }, 'template-import-kandidat.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }

    public function importCandidates(): void
    {
        $actor = $this->authorizeAdmin();
        $this->feedback = '';
        try {
            $this->validate(['importFile' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:'.config('candidate_import.max_kilobytes')]]);
            $count = app(CandidateImportService::class)->import($actor, $this->importFile->getRealPath());
            $this->navigate('create');
            $this->candidateMode = 'existing';
            $this->reset('candidateSearch', 'existingCandidateIds');
            $this->feedback = "$count akun kandidat berhasil diimport. Kandidat sudah tersedia di dropdown Kandidat terdaftar di bawah. Pilih kandidat untuk membuat lamaran.";
        } finally {
            if ($this->importFile instanceof TemporaryUploadedFile) {
                $this->importFile->delete();
            }
            $this->reset('importFile');
        }
    }

    protected string $view = 'filament.pages.recruitment';

    protected static ?string $title = 'Rekrutmen';

    protected static ?string $navigationLabel = 'Portal Rekrutmen';

    protected static ?string $slug = 'recruitment';

    protected static bool $shouldRegisterNavigation = false;

    public string $section = 'dashboard';

    public string $search = '';

    public string $candidateMode = 'new';

    public string $candidateSearch = '';

    public array $existingCandidateIds = [];

    public function updatedCandidateMode(): void
    {
        $this->reset('candidateSearch', 'existingCandidateIds');
        $this->resetValidation();
    }

    private function eligibleCandidates(): Builder
    {
        return User::query()->where('role', Role::Candidate)->where('active', true)
            ->whereDoesntHave('applications', fn ($query) => $query->whereNull('archived_at'));
    }

    public string $positionFilter = '';

    public string $periodFilter = '';

    public string $portfolioFilter = '';

    public string $testFilter = '';

    public bool $overdue = false;

    public bool $archived = false;

    #[Locked]
    public ?int $applicationId = null;

    public string $tab = 'Profil';

    public ?int $versionId = null;

    public array $candidate = ['name' => '', 'email' => '', 'position_id' => '', 'recruitment_period_id' => '', 'portfolio_deadline' => '', 'test_deadline' => '', 'task_label' => '', 'instructions' => ''];

    public array $edit = ['name' => '', 'email' => '', 'active' => true];

    public string $reason = '';

    public string $deadline = '';

    public string $action = '';

    public string $feedback = '';

    public array $catalog = ['name' => '', 'starts_at' => '', 'ends_at' => '', 'active' => true];

    public ?int $catalogId = null;

    public array $settings = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->navigate(request()->query('section', 'dashboard'));
    }

    private function authorizeAdmin(): User
    {
        $user = auth()->user();
        abort_unless($user && $user->fresh()->active && $user->role === Role::Admin && $user->getAppAuthenticationSecret(), 403);

        return $user;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetApplicationFilters(): void
    {
        $this->authorizeAdmin();
        $this->reset('search', 'positionFilter', 'periodFilter', 'portfolioFilter', 'testFilter', 'overdue', 'archived');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (str_ends_with($property, 'Filter') || in_array($property, ['archived', 'overdue'], true)) {
            $this->resetPage();
        }
    }

    public function navigate(string $section): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($section, ['dashboard', 'applications', 'create', 'import', 'positions', 'periods', 'audit', 'settings', 'operations'], true), 404);
        $this->section = $section;
        $this->resetPage();
        $this->applicationId = null;
        $this->resetValidation();
        $this->feedback = '';
        $this->action = '';
        $this->reason = '';
        $this->catalogId = null;
        $this->catalog = ['name' => '', 'starts_at' => '', 'ends_at' => '', 'active' => true];
        if ($section === 'settings') {
            foreach (['quota_mb', 'temporary_password_hours', 'retention_months', 'retention_enabled', 'privacy_contact', 'timezone', 'uploads'] as $key) {
                $this->settings[$key] = AppSetting::valueFor($key);
            }
        }
    }

    public function createCandidate(): void
    {
        $actor = $this->authorizeAdmin();
        $this->validate(['candidateMode' => ['required', Rule::in(['new', 'existing'])]]);
        if ($this->candidateMode === 'existing') {
            $this->validate([
                'existingCandidateIds' => 'required|array|min:1|max:100',
                'existingCandidateIds.*' => 'required|integer|distinct',
            ]);
            $results = DB::transaction(function () use ($actor) {
                $users = $this->eligibleCandidates()->whereIn('id', $this->existingCandidateIds)
                    ->orderBy('id')->lockForUpdate()->get();
                if ($users->count() !== count($this->existingCandidateIds)) {
                    throw ValidationException::withMessages([
                        'existingCandidateIds' => 'Ada kandidat yang tidak lagi aktif atau sudah memiliki lamaran aktif. Periksa kembali pilihan Anda.',
                    ]);
                }

                return $users->map(fn (User $user) => app(RecruitmentService::class)->create($actor, [
                    ...$this->candidate, 'name' => $user->name, 'email' => $user->email,
                ]));
            });
            $count = $results->count();
            $this->reset('existingCandidateIds', 'candidateSearch');
            if ($count === 1) {
                $this->openApplication($results->first()['application']->id);
            } else {
                $this->reset('search', 'positionFilter', 'periodFilter', 'portfolioFilter', 'testFilter', 'overdue', 'archived');
                $this->navigate('applications');
            }
            $this->feedback = "$count lamaran berhasil dibuat. Akun dan password kandidat tetap digunakan.";

            return;
        }

        $result = app(RecruitmentService::class)->create($actor, $this->candidate);
        $this->openApplication($result['application']->id);
        if ($result['password']) {
            $this->dispatch('access-created', password: $result['password'], email: $result['application']->user->email);
        }
        $this->feedback = 'Lamaran dibuat. Akun yang sudah ada digunakan kembali tanpa reset password.';
    }

    public function openApplication(int $id): void
    {
        $this->authorizeAdmin();
        $application = RecruitmentApplication::with('user')->findOrFail($id);
        $this->applicationId = $id;
        $this->section = 'detail';
        $this->tab = 'Profil';
        $this->versionId = null;
        $this->edit = $application->user->only('name', 'email', 'active');
        $this->reason = '';
        $this->action = '';
        $this->resetValidation();
    }

    public function selectTab(string $tab): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($tab, ['Profil', 'Portofolio', 'Tes Teknis', 'Riwayat'], true), 404);
        $this->tab = $tab;
        $this->versionId = null;
        $this->action = '';
        $this->resetValidation();
    }

    private function application(): RecruitmentApplication
    {
        return RecruitmentApplication::findOrFail($this->applicationId);
    }

    private function submission(): Submission
    {
        return $this->application()->submissions()->where('type', $this->tab === 'Portofolio' ? 'portfolio' : 'technical_test')->firstOrFail();
    }

    public function prepareAction(string $action): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($action, ['revision', 'deadline', 'exempt', 'archive', 'reset', 'edit'], true), 422);
        $this->action = $action;
        $this->reason = '';
        $this->deadline = '';
        $this->resetValidation();
    }

    public function applyAction(): void
    {
        $actor = $this->authorizeAdmin();
        $service = app(RecruitmentService::class);
        $service->adminReason($actor, $this->reason);
        $app = $this->application();
        if (in_array($this->action, ['revision', 'deadline'], true)) {
            $this->validate(['deadline' => 'required|date']);
        }
        switch ($this->action) {
            case 'revision':app(SubmissionService::class)->revise($this->submission(), $actor, $this->reason, $this->deadline);
                break;
            case 'deadline':app(SubmissionService::class)->deadline($this->submission(), $actor, $this->reason, $this->deadline);
                break;
            case 'exempt':app(SubmissionService::class)->exempt($this->submission(), $actor, $this->reason);
                break;
            case 'archive':$service->archive($actor, $app, $this->reason);
                break;
            case 'reset':$password = $service->resetAccess($actor, $app->user, $this->reason);
                $this->dispatch('access-created', password: $password, email: $app->user->email);
                break;
            case 'edit':$service->updateCandidate($actor, $app->user, $this->edit, $this->reason);
                break;
            default:abort(422);
        }
        $this->feedback = $this->action === 'revision'
            ? 'Revisi berhasil dibuka. Kandidat dapat memperbarui dokumen hingga tenggat baru. Versi final sebelumnya tetap tersimpan.'
            : 'Perubahan berhasil disimpan dan dicatat dalam riwayat.';
        $this->action = '';
        $this->reason = '';
    }

    public function updateAssignment(): void
    {
        $actor = $this->authorizeAdmin();
        app(RecruitmentService::class)->adminReason($actor, $this->reason);
        $this->validate(['candidate.position_id' => 'required|exists:positions,id', 'candidate.task_label' => 'required|string|max:255', 'candidate.instructions' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($actor) {
            $app = RecruitmentApplication::lockForUpdate()->findOrFail($this->applicationId);
            abort_if($app->archived_at, 422);
            $before = $app->position_id;
            $app->update(['position_id' => $this->candidate['position_id']]);
            $test = $app->submissions()->where('type', 'technical_test')->lockForUpdate()->firstOrFail();
            abort_if($test->current_version_id, 422, 'Petunjuk tes final tidak dapat diubah.');
            $test->update(['task_label' => $this->candidate['task_label'], 'instructions' => $this->candidate['instructions']]);
            AuditLog::record('application.assignment_changed', $app, $actor, $this->reason, ['position_before' => $before, 'position_after' => $app->position_id]);
        });
        $this->feedback = 'Penugasan diperbarui.';
    }

    public function editCatalog(int $id): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($this->section, ['positions', 'periods'], true), 422);
        $record = ($this->section === 'positions' ? Position::query() : RecruitmentPeriod::query())->findOrFail($id);
        $this->catalogId = $id;
        $this->catalog = ['name' => $record->name, 'active' => $record->active, 'starts_at' => $record->starts_at?->format('Y-m-d') ?? '', 'ends_at' => $record->ends_at?->format('Y-m-d') ?? ''];
    }

    public function saveCatalog(): void
    {
        $actor = $this->authorizeAdmin();
        abort_unless(in_array($this->section, ['positions', 'periods'], true), 422);
        $isPosition = $this->section === 'positions';
        $this->validate(['catalog.name' => ['required', 'string', 'max:255', Rule::unique($isPosition ? 'positions' : 'recruitment_periods', 'name')->ignore($this->catalogId)], 'catalog.active' => 'boolean', ...($isPosition ? [] : ['catalog.starts_at' => 'required|date', 'catalog.ends_at' => 'required|date|after_or_equal:catalog.starts_at'])]);
        $model = $isPosition ? Position::class : RecruitmentPeriod::class;
        $record = $model::updateOrCreate(['id' => $this->catalogId], $isPosition ? array_intersect_key($this->catalog, array_flip(['name', 'active'])) : $this->catalog);
        AuditLog::record('catalog.saved', $record, $actor);
        $this->catalogId = null;
        $this->catalog = ['name' => '', 'starts_at' => '', 'ends_at' => '', 'active' => true];
        $this->feedback = 'Data berhasil disimpan.';
    }

    public function saveSettings(): void
    {
        $actor = $this->authorizeAdmin();
        app(RecruitmentService::class)->adminReason($actor, $this->reason);
        $this->validate(['settings.quota_mb' => 'required|integer|min:25|max:10240', 'settings.temporary_password_hours' => 'required|integer|min:1|max:720', 'settings.retention_months' => 'required|integer|min:1|max:120', 'settings.retention_enabled' => 'boolean', 'settings.privacy_contact' => 'required|string|max:255', 'settings.timezone' => ['required', Rule::in(timezone_identifiers_list())], 'settings.uploads.*.max_mb' => 'required|integer|min:1|max:25', 'settings.uploads.*.max_files' => 'required|integer|min:1|max:10']);
        $this->settings['uploads']['portfolio_main']['max_files'] = 1;
        foreach (config('submissions.uploads') as $purpose => $limits) {
            $this->settings['uploads'][$purpose]['extensions'] = $limits['extensions'];
        }
        DB::transaction(function () use ($actor) {
            foreach ($this->settings as $key => $value) {
                abort_unless(in_array($key, ['quota_mb', 'temporary_password_hours', 'retention_months', 'retention_enabled', 'privacy_contact', 'timezone', 'uploads'], true), 422);
                $before = AppSetting::valueFor($key);
                $record = AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
                AuditLog::record('settings.updated', $record, $actor, $this->reason, ['key' => $key, 'before' => $before, 'after' => $value]);
            }
        });
        $this->feedback = 'Pengaturan diperbarui.';
    }

    protected function getViewData(): array
    {
        $this->authorizeAdmin();
        $query = RecruitmentApplication::query()->with(['user', 'position', 'period', 'submissions']);
        $query->when(! $this->archived, fn ($q) => $q->whereNull('archived_at'))->when($this->archived, fn ($q) => $q->whereNotNull('archived_at'));
        $query->when($this->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($s) => $s->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%'))));
        $query->when($this->positionFilter, fn ($q) => $q->where('position_id', $this->positionFilter))->when($this->periodFilter, fn ($q) => $q->where('recruitment_period_id', $this->periodFilter));
        foreach (['portfolio' => $this->portfolioFilter, 'technical_test' => $this->testFilter] as $type => $status) {
            if ($status) {
                $query->whereHas('submissions', fn ($s) => $s->where('type', $type)->where('status', $status));
            }
        }
        $query->when($this->overdue, fn ($q) => $q->whereHas('submissions', fn ($s) => $s->where('deadline', '<', now())->whereIn('status', ['not_started', 'draft', 'revision'])));
        $application = $this->applicationId ? RecruitmentApplication::with(['user', 'position', 'period', 'submissions'])->findOrFail($this->applicationId) : null;
        $submission = $application?->submissions->firstWhere('type', $this->tab === 'Portofolio' ? SubmissionType::Portfolio : SubmissionType::TechnicalTest);
        $versions = $submission?->versions()->where('status', 'final')->latest('number')->get() ?? collect();
        $version = $this->versionId ? $versions->firstWhere('id', $this->versionId) : $versions->first();
        if ($version) {
            $version->load('attachments.file');
        }
        $base = Submission::whereHas('application', fn ($q) => $q->whereNull('archived_at'));

        $candidateOptions = collect();
        if ($this->section === 'create' && $this->candidateMode === 'existing') {
            $term = mb_substr(trim($this->candidateSearch), 0, 255);
            $candidateOptions = $this->eligibleCandidates()
                ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))
                ->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name', 'email']);
        }

        return ['candidateOptions' => $candidateOptions, 'statusCounts' => (clone $base)->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get(), 'applications' => in_array($this->section, ['dashboard', 'applications'], true) ? $query->latest()->paginate(15) : null, 'positions' => Position::orderBy('name')->get(), 'periods' => RecruitmentPeriod::latest()->get(), 'application' => $application, 'submission' => $submission, 'versions' => $versions, 'version' => $version,
            'stats' => ['active' => RecruitmentApplication::whereNull('archived_at')->count(), 'portfolio' => (clone $base)->where('type', 'portfolio')->where('status', 'submitted')->count(), 'test' => (clone $base)->where('type', 'technical_test')->where('status', 'submitted')->count(), 'overdue' => (clone $base)->where('deadline', '<', now())->whereIn('status', ['not_started', 'draft', 'revision'])->count()],
            'audits' => in_array($this->section, ['audit', 'detail'], true) ? AuditLog::with('actor')->when($application, fn ($q) => $q->where(fn ($s) => $s->where(fn ($a) => $a->where('target_type', RecruitmentApplication::class)->where('target_id', $application->id))->orWhere(fn ($a) => $a->where('target_type', Submission::class)->whereIn('target_id', $application->submissions->pluck('id')))->orWhere(fn ($a) => $a->where('target_type', User::class)->where('target_id', $application->user_id))))->latest()->paginate(20, pageName: 'auditPage') : collect(),
            'deliveries' => $this->section === 'operations' ? EmailDelivery::with('submission.application.user')->latest()->paginate(20) : collect(),
            'failedScans' => $this->section === 'operations' ? UploadedFile::whereIn('scan_status', ['failed', 'pending'])->with('submission.application.user')->latest()->limit(30)->get() : collect()];
    }
}
