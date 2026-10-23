<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Jobs\BuildPortfolioExport;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\PortfolioExport;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CandidateImportService;
use App\Services\RecruitmentService;
use App\Services\RecruitmentToolsService;
use App\Services\SubmissionService;
use App\Services\TechnicalAssessmentService;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public array $bulk = ['position_id' => '', 'recruitment_period_id' => '', 'type' => 'portfolio', 'deadline' => '', 'reason' => ''];

    public array $recipientIds = [];

    public string $recipientSearch = '';

    public string $recipientPositionFilter = '';

    public string $recipientPeriodFilter = '';

    public string $accessStatusFilter = '';

    public string $hrMessage = '';

    public bool $confirmAccess = false;

    public function bulkDeadlines(): void
    {
        $count = app(RecruitmentToolsService::class)->deadlines($this->authorizeAdmin(), $this->bulk);
        $this->feedback = "$count tenggat berhasil diperpanjang. Pengumpulan final dan lamaran arsip tidak diubah.";
    }

    public function sendCandidateAccess(): void
    {
        $actor = $this->authorizeAdmin();
        $this->validate([
            'confirmAccess' => 'accepted',
            'recipientIds' => 'required|array|min:1|max:100',
            'recipientIds.*' => 'required|integer|distinct',
        ]);
        if ($this->eligibleRecipients()->whereIn('id', $this->recipientIds)->count() !== count($this->recipientIds)) {
            throw ValidationException::withMessages(['recipientIds' => 'Ada kandidat yang sudah mengunggah portofolio atau tidak sesuai filter. Pilih ulang penerima.']);
        }
        $count = app(RecruitmentToolsService::class)->sendAccess($actor, $this->recipientIds, $this->hrMessage);
        $this->reset('recipientIds', 'confirmAccess');
        $this->feedback = "$count email akses dibuat. Periksa status pengiriman di bawah.";
    }

    public function retryAccess(int $id): void
    {
        $actor = $this->authorizeAdmin();
        $service = app(RecruitmentToolsService::class);
        $service->validateMailer();
        $delivery = AccessDelivery::findOrFail($id);
        abort_unless($delivery->status === 'failed' && $delivery->expires_at->isFuture(), 422);
        if (AccessDelivery::whereKey($id)->where('status', 'failed')->update(['status' => 'pending'])) {
            AuditLog::record('account.access_email_retried', $delivery, $actor);
            $service->dispatchAccess($delivery->fresh());
        }
        $this->feedback = 'Email dijadwalkan ulang. Periksa status pengiriman.';
    }

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

    public function selectCandidatesByEmail(string $text): void
    {
        $this->authorizeAdmin();
        $this->resetValidation('candidateSearch');
        $this->existingCandidateIds = app(RecruitmentService::class)->selectByEmails($this->eligibleCandidates()->select('id', 'email'), $text, $this->existingCandidateIds, 'candidateSearch');
        $this->candidateSearch = '';
        $this->feedback = count($this->existingCandidateIds).' kandidat dari email berhasil dipilih.';
    }

    public function selectRecipientsByEmail(string $text): void
    {
        $this->authorizeAdmin();
        $this->resetValidation('recipientSearch');
        $this->recipientIds = app(RecruitmentService::class)->selectByEmails($this->eligibleRecipients()->select('id', 'email'), $text, $this->recipientIds, 'recipientSearch');
        $this->recipientSearch = '';
        $this->confirmAccess = false;
        $this->feedback = count($this->recipientIds).' penerima email dipilih.';
    }

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

    private function eligibleRecipients(): Builder
    {
        return User::query()->where('role', Role::Candidate)->where('active', true)
            ->whereDoesntHave('applications', fn (Builder $query) => $query->whereNull('archived_at')
                ->whereHas('files', fn (Builder $files) => $files->whereIn('purpose', ['portfolio_main', 'portfolio_evidence'])))
            ->when($this->recipientPositionFilter !== '' || $this->recipientPeriodFilter !== '', fn (Builder $query) => $query
                ->whereHas('applications', fn (Builder $applications) => $applications->whereNull('archived_at')
                    ->when($this->recipientPositionFilter !== '', fn (Builder $q) => $q->where('position_id', $this->recipientPositionFilter))
                    ->when($this->recipientPeriodFilter !== '', fn (Builder $q) => $q->where('recruitment_period_id', $this->recipientPeriodFilter))));
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

    public array $candidate = ['name' => '', 'email' => '', 'position_id' => '', 'recruitment_period_id' => '', 'portfolio_deadline' => ''];

    public array $edit = ['name' => '', 'email' => '', 'active' => true];

    public string $reason = '';

    public string $deadline = '';

    public string $action = '';

    public string $feedback = '';

    #[Locked]
    public array $candidateDeactivation = [];

    #[Locked]
    public ?int $cleanupAuditId = null;

    public string $deactivationEmail = '';

    public function confirmCandidateDeactivation(int $userId): void
    {
        $this->candidateDeactivation = app(RecruitmentService::class)->candidateDeactivationSummary($this->authorizeAdmin(), $userId);
        $this->reset('deactivationEmail', 'reason', 'action');
        $this->resetValidation();
    }

    public function cancelCandidateDeactivation(): void
    {
        $this->reset('candidateDeactivation', 'deactivationEmail');
        $this->resetValidation();
    }

    public function deactivateCandidate(): void
    {
        $actor = $this->authorizeAdmin();
        abort_unless($this->candidateDeactivation, 422);
        $this->validate([
            'deactivationEmail' => ['required', 'email', Rule::in([$this->candidateDeactivation['email']])],
            'reason' => 'required|string|min:5|max:2000',
        ], ['deactivationEmail.in' => 'Email konfirmasi harus sama dengan email kandidat.']);
        $audit = app(RecruitmentService::class)->deactivateCandidate($actor, $this->candidateDeactivation['user_id'], $this->candidateDeactivation, $this->reason);
        $pending = $audit->fresh()->metadata['cleanup_status'] === 'pending';
        $this->navigate('applications');
        $this->reset('existingCandidateIds', 'recipientIds');
        $this->cleanupAuditId = $pending ? $audit->id : null;
        $this->feedback = $pending
            ? 'Akun kandidat sudah dinonaktifkan. Sebagian file belum berhasil dibersihkan. Histori tetap tersimpan.'
            : 'Akun kandidat dinonaktifkan dan seluruh file terkait berhasil dihapus. Profil, lamaran, respons form, dan histori tetap tersimpan.';
    }

    public function retryCandidateCleanup(): void
    {
        $this->authorizeAdmin();
        abort_unless($this->cleanupAuditId, 422);
        if (app(RecruitmentService::class)->cleanupCandidateFiles($this->cleanupAuditId)) {
            $this->cleanupAuditId = null;
            $this->feedback = 'Seluruh file terkait berhasil dibersihkan. Histori kandidat tetap tersimpan.';
        } else {
            $this->addError('candidateCleanup', 'Sebagian file masih gagal dihapus. Periksa izin penyimpanan dan log server, lalu coba kembali.');
        }
    }

    public array $catalog = ['name' => '', 'starts_at' => '', 'ends_at' => '', 'active' => true];

    public ?int $catalogId = null;

    public array $settings = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->navigate(request()->query('section', 'dashboard'));
        $this->cleanupAuditId = AuditLog::where('action', 'candidate.deactivated')->where('metadata->cleanup_status', 'pending')->latest('id')->value('id');
    }

    private function authorizeAdmin(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user && $user->active && ! $user->must_change_password && $user->role === Role::Admin && $user->getAppAuthenticationSecret(), 403);

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
        if ($property === 'accessStatusFilter') {
            $this->resetPage('accessPage');

            return;
        }
        if (in_array($property, ['recipientPositionFilter', 'recipientPeriodFilter'], true)) {
            $this->reset('recipientIds', 'confirmAccess');
        }
        if (str_ends_with($property, 'Filter') || in_array($property, ['archived', 'overdue'], true)) {
            $this->resetPage();
        }
    }

    public function navigate(string $section): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($section, ['dashboard', 'applications', 'create', 'import', 'positions', 'periods', 'audit', 'settings', 'operations', 'tools'], true), 404);
        $this->section = $section;
        $this->resetPage();
        $this->applicationId = null;
        $this->reset('candidateDeactivation', 'deactivationEmail');
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
        $this->reset('candidateDeactivation', 'deactivationEmail');
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
        $this->reset('candidateDeactivation', 'deactivationEmail');
        abort_unless(in_array($tab, ['Profil', 'Portofolio', 'Tes Teknis', 'Riwayat', 'Arsip Tes Upload'], true), 404);
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
        abort_unless($this->tab === 'Portofolio', 422);

        return $this->application()->submissions()->where('type', SubmissionType::Portfolio)->firstOrFail();
    }

    public function prepareAction(string $action): void
    {
        $this->authorizeAdmin();
        $this->reset('candidateDeactivation', 'deactivationEmail');
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
        $this->validate(['candidate.position_id' => 'required|exists:positions,id']);
        DB::transaction(function () use ($actor) {
            $app = RecruitmentApplication::lockForUpdate()->findOrFail($this->applicationId);
            abort_if($app->archived_at, 422);
            $before = $app->position_id;
            $app->update(['position_id' => $this->candidate['position_id']]);
            abort_if($app->technicalAssessmentAttempts()->exists() || $app->submissions()->where('type', SubmissionType::TechnicalTest)->whereNotNull('current_version_id')->exists(), 422, 'Posisi tidak dapat diubah setelah tes ditugaskan atau hasil lama dikirim final.');
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

    private function filteredApplications(): Builder
    {
        $query = RecruitmentApplication::query()->with(['user', 'position', 'period', 'submissions', 'technicalAssessmentAttempts']);
        $query->when(! $this->archived, fn ($q) => $q->whereNull('archived_at'))->when($this->archived, fn ($q) => $q->whereNotNull('archived_at'));
        $query->when($this->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($s) => $s->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%'))));
        $query->when($this->positionFilter, fn ($q) => $q->where('position_id', $this->positionFilter))->when($this->periodFilter, fn ($q) => $q->where('recruitment_period_id', $this->periodFilter));
        $query->when($this->portfolioFilter, fn ($q) => $q->whereHas('submissions', fn ($s) => $s->where('type', SubmissionType::Portfolio)->where('status', $this->portfolioFilter)));
        $query->when($this->testFilter, fn ($q) => $q->whereHas('technicalAssessmentAttempts', function (Builder $attempts): void {
            match ($this->testFilter) {
                'completed' => $attempts->whereNotNull('completed_at'),
                'in_progress' => $attempts->whereNull('completed_at')->whereNotNull('section_started_at'),
                default => $attempts->whereNull('completed_at')->whereNull('section_started_at'),
            };
        }));
        $query->when($this->overdue, fn ($q) => $q->whereHas('submissions', fn ($s) => $s->where('type', SubmissionType::Portfolio)->where('deadline', '<', now())->whereIn('status', ['not_started', 'draft', 'revision'])));

        return $query;
    }

    public function exportPortfolios(): void
    {
        $actor = $this->authorizeAdmin();
        $this->resetValidation('portfolioExport');
        $this->feedback = '';
        try {
            $export = DB::transaction(function () use ($actor): PortfolioExport {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (PortfolioExport::where('actor_id', $actor->id)->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->exists()) {
                    throw ValidationException::withMessages(['portfolioExport' => 'Export sebelumnya masih diproses. Tunggu hingga selesai sebelum membuat export baru.']);
                }
                $limit = (int) config('submissions.portfolio_export.max_candidates');
                $ids = $this->filteredApplications()->reorder('id')->limit($limit + 1)->pluck('id')->all();
                if (! $ids || count($ids) > $limit) {
                    throw ValidationException::withMessages(['portfolioExport' => "Pilih filter dengan 1–$limit kandidat untuk export offline."]);
                }

                return PortfolioExport::create(['actor_id' => $actor->id, 'application_ids' => $ids, 'path' => 'exports/'.Str::uuid().'.zip', 'candidate_count' => count($ids), 'expires_at' => now()->addDay()]);
            });
            BuildPortfolioExport::dispatch($export->id);
            $this->feedback = 'Export masuk antrean. Status akan diperbarui otomatis; ZIP dapat diunduh setelah selesai.';
        } catch (ValidationException $exception) {
            $this->addError('portfolioExport', $exception->getMessage());
        } catch (\Throwable $exception) {
            if (isset($export)) {
                (new BuildPortfolioExport($export->id))->failed($exception);
            }
            report($exception);
            $this->addError('portfolioExport', 'Export gagal dijadwalkan. Periksa antrean lalu coba kembali.');
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAdmin();
        $query = $this->filteredApplications();
        $application = $this->applicationId ? RecruitmentApplication::with(['user', 'position', 'period', 'submissions'])->findOrFail($this->applicationId) : null;
        $submission = match ($this->tab) {
            'Portofolio' => $application?->submissions->firstWhere('type', SubmissionType::Portfolio),
            'Arsip Tes Upload' => $application?->submissions->firstWhere('type', SubmissionType::TechnicalTest),
            default => null,
        };
        $versions = $submission?->versions()->where('status', 'final')->latest('number')->get() ?? collect();
        $version = $this->versionId ? $versions->firstWhere('id', $this->versionId) : $versions->first();
        if ($version) {
            $version->load('attachments.file');
        }
        $base = Submission::where('type', SubmissionType::Portfolio)->whereHas('application', fn ($q) => $q->whereNull('archived_at'));

        $candidateOptions = collect();
        if ($this->section === 'create' && $this->candidateMode === 'existing') {
            $term = mb_substr(trim($this->candidateSearch), 0, 255);
            $candidateOptions = $this->eligibleCandidates()
                ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))
                ->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name', 'email']);
        }

        return [
            'portfolioExports' => in_array($this->section, ['dashboard', 'applications'], true) ? PortfolioExport::where('actor_id', auth()->id())->where('expires_at', '>', now())->latest()->limit(5)->get() : collect(),
            'recipients' => $this->section === 'tools' ? $this->eligibleRecipients()->when($this->recipientSearch, fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.mb_substr($this->recipientSearch, 0, 255).'%')->orWhere('email', 'like', '%'.mb_substr($this->recipientSearch, 0, 255).'%')))->orderBy('name')->orderBy('id')->limit(100)->get() : collect(),
            'accessDeliveries' => $this->section === 'tools' ? AccessDelivery::when($this->accessStatusFilter !== '', fn (Builder $query) => $query->where('status', $this->accessStatusFilter))->latest()->paginate(15, pageName: 'accessPage') : collect(),
            'bulkCount' => $this->section === 'tools' ? Submission::where('type', $this->bulk['type'])->whereIn('status', ['not_started', 'draft', 'revision'])->whereHas('application', fn ($q) => $q->whereNull('archived_at')->where('position_id', $this->bulk['position_id'])->where('recruitment_period_id', $this->bulk['recruitment_period_id']))->count() : 0,
            'candidateOptions' => $candidateOptions, 'statusCounts' => (clone $base)->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get(), 'applications' => in_array($this->section, ['dashboard', 'applications'], true) ? $query->latest()->paginate(15) : null, 'positions' => Position::orderBy('name')->get(), 'periods' => RecruitmentPeriod::latest()->get(), 'application' => $application, 'submission' => $submission, 'versions' => $versions, 'version' => $version,
            'stats' => ['active' => RecruitmentApplication::whereNull('archived_at')->count(), 'portfolio' => (clone $base)->where('type', 'portfolio')->where('status', 'submitted')->count(), 'test' => TechnicalAssessmentService::available() ? TechnicalAssessmentAttempt::whereNotNull('completed_at')->whereHas('application', fn ($q) => $q->whereNull('archived_at')->whereNull('purged_at'))->count() : 0, 'overdue' => (clone $base)->where('deadline', '<', now())->whereIn('status', ['not_started', 'draft', 'revision'])->count()],
            'audits' => in_array($this->section, ['audit', 'detail'], true) ? AuditLog::with('actor')->when($application, fn ($q) => $q->where(fn ($s) => $s->where(fn ($a) => $a->where('target_type', RecruitmentApplication::class)->where('target_id', $application->id))->orWhere(fn ($a) => $a->where('target_type', Submission::class)->whereIn('target_id', $application->submissions->pluck('id')))->orWhere(fn ($a) => $a->where('target_type', User::class)->where('target_id', $application->user_id))))->latest()->paginate(20, pageName: 'auditPage') : collect(),
            'deliveries' => $this->section === 'operations' ? EmailDelivery::with('submission.application.user')->latest()->paginate(20) : collect(),
            'failedScans' => $this->section === 'operations' ? UploadedFile::whereIn('scan_status', ['failed', 'pending'])->with('submission.application.user')->latest()->limit(30)->get() : collect()];
    }
}
