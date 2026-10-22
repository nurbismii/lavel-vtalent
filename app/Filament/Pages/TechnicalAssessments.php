<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\RecruitmentApplication;
use App\Models\TechnicalAssessmentAttempt;
use App\Services\RecruitmentService;
use App\Services\TechnicalAssessmentService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class TechnicalAssessments extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.technical-assessments';

    protected static ?string $title = 'Tes teknis online';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public string $form = 'A';

    public array $applicationIds = [];

    public array $durations = [];

    public string $packageTitle = '';

    public array $sectionTitles = [];

    public array $questionTitles = [];

    public string $opensAt = '';

    public string $deadline = '';

    public bool $reviewed = false;

    public string $search = '';

    public string $resultSearch = '';

    public string $feedback = '';

    #[Locked]
    public ?int $attemptId = null;

    #[Locked]
    public ?int $deletingAttemptId = null;

    public array $ratings = [];

    public string $reviewStatus = '';

    public string $reviewNotes = '';

    public int $extraMinutes = 5;

    public string $extensionReason = '';

    public bool $confirmStageAction = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return TechnicalAssessmentService::available() && $user?->active && $user->role === Role::Admin && ! $user->must_change_password;
    }

    public function getHeading(): ?string
    {
        return null;
    }

    private function actor(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        $this->actor();
        $this->updatedForm();
        $this->resultSearch = mb_substr((string) request()->query('candidate', ''), 0, 255);
    }

    public function updatedForm(): void
    {
        $this->actor();
        $packages = app(TechnicalAssessmentService::class)->packages();
        abort_unless(isset($packages[$this->form]), 422);
        $this->durations = array_map(fn (array $section): int => $section['seconds'], $packages[$this->form]['sections']);
        $this->packageTitle = $packages[$this->form]['title'];
        $this->sectionTitles = array_column($packages[$this->form]['sections'], 'title');
        $this->questionTitles = [];
        foreach ($packages[$this->form]['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                $this->questionTitles[$question['id']] = ['label' => $question['label'] ?? $question['id'], 'prompt' => $question['prompt']];
            }
        }
        $this->resetValidation();
        $this->reviewed = false;
    }

    public function savePackage(): void
    {
        $this->actor();
        $this->resetValidation();
        app(TechnicalAssessmentService::class)->updatePackageLabels(auth()->user(), $this->form, [
            'title' => $this->packageTitle, 'sections' => $this->sectionTitles, 'questions' => $this->questionTitles,
        ]);
        $this->reviewed = false;
        $this->feedback = 'Nama paket dan soal berhasil diperbarui untuk penugasan baru. Sesi yang sudah ditugaskan tetap menggunakan materi sebelumnya.';
    }

    public function assign(): void
    {
        $this->actor();
        $this->resetValidation();
        $this->validate(['applicationIds' => ['required', 'array', 'min:1', 'max:100'], 'applicationIds.*' => ['integer', 'distinct'], 'opensAt' => ['required', 'date'], 'deadline' => ['required', 'date', 'after:opensAt'], 'reviewed' => ['accepted']]);
        $zone = AppSetting::valueFor('timezone');
        $data = ['form' => $this->form, 'durations' => $this->durations, 'opens_at' => Carbon::parse($this->opensAt, $zone)->utc()->toDateTimeString(), 'deadline' => Carbon::parse($this->deadline, $zone)->utc()->toDateTimeString(), 'reviewed' => $this->reviewed];
        $created = DB::transaction(function () use ($data): int {
            $created = 0;
            foreach ($this->applicationIds as $applicationId) {
                $attempt = app(TechnicalAssessmentService::class)->assign(auth()->user(), $data + ['application_id' => $applicationId]);
                $created += (int) $attempt->wasRecentlyCreated;
            }

            return $created;
        });
        $this->applicationIds = [];
        $this->feedback = $created.' penugasan dibuat. Penugasan versi yang sudah ada dilewati tanpa perubahan.';
    }

    private function eligibleApplications(): Builder
    {
        return RecruitmentApplication::whereNull('archived_at')->whereNull('purged_at')
            ->whereHas('user', fn ($query) => $query->where('active', true)->where('role', Role::Candidate));
    }

    public function selectApplicationsByEmail(string $text): void
    {
        $this->actor();
        $this->resetValidation('search');
        $query = $this->eligibleApplications()->join('users', 'users.id', '=', 'recruitment_applications.user_id')
            ->select('recruitment_applications.id', 'users.email');
        $this->applicationIds = app(RecruitmentService::class)->selectByEmails($query, $text, $this->applicationIds, 'search');
        $this->search = '';
        $this->feedback = count($this->applicationIds).' lamaran kandidat dipilih untuk tes teknis.';
    }

    public function updatedResultSearch(): void
    {
        $this->resetPage();
    }

    public function selectAttempt(int $id): void
    {
        $this->actor();
        $attempt = DB::transaction(function () use ($id): TechnicalAssessmentAttempt {
            $attempt = TechnicalAssessmentAttempt::lockForUpdate()->findOrFail($id);
            abort_if($attempt->application->purged_at, 404);
            app(TechnicalAssessmentService::class)->expire($attempt);

            return $attempt;
        });
        $this->attemptId = $id;
        $this->ratings = $attempt->review['ratings'] ?? [];
        $this->reviewStatus = $attempt->review['status'] ?? '';
        $this->reviewNotes = $attempt->review['notes'] ?? '';
        $this->extensionReason = '';
        $this->confirmStageAction = false;
        $this->reset('deletingAttemptId');
        $this->resetValidation();
    }

    public function confirmAttemptDeletion(int $id): void
    {
        $this->actor();
        $this->deletingAttemptId = TechnicalAssessmentAttempt::whereHas('application', fn ($query) => $query->whereNull('purged_at'))->findOrFail($id)->id;
        $this->reset('feedback');
        $this->resetValidation();
    }

    public function cancelAttemptDeletion(): void
    {
        $this->actor();
        $this->reset('deletingAttemptId');
    }

    public function deleteAttempt(): void
    {
        $this->actor();
        abort_unless($this->deletingAttemptId, 422);
        app(TechnicalAssessmentService::class)->deleteAttempt(auth()->user(), $this->deletingAttemptId);
        if ($this->attemptId === $this->deletingAttemptId) {
            $this->reset('attemptId', 'ratings', 'reviewStatus', 'reviewNotes', 'extraMinutes', 'extensionReason', 'confirmStageAction');
        }
        $this->reset('deletingAttemptId');
        $this->resetValidation();
        $this->resetPage();
        $this->feedback = 'Hasil tes teknis berhasil dihapus beserta penugasan, jawaban, dan penilaiannya. Akun dan lamaran tetap tersedia.';
    }

    public function control(int $section, int $revision, string $action): void
    {
        $this->actor();
        $this->resetValidation();
        abort_unless($this->attemptId, 422);
        $this->validate(['confirmStageAction' => ['accepted']]);
        app(TechnicalAssessmentService::class)->control(auth()->user(), $this->attemptId, $section, $revision, $action);
        $this->confirmStageAction = false;
        $this->feedback = 'Status sesi diperbarui. Kandidat akan menerima perubahan secara otomatis.';
    }

    public function extend(int $section, int $revision): void
    {
        $this->actor();
        $this->resetValidation();
        abort_unless($this->attemptId, 422);
        $this->validate(['extraMinutes' => ['integer', 'min:1', 'max:120'], 'extensionReason' => ['required', 'string', 'min:10', 'max:2000']]);
        app(TechnicalAssessmentService::class)->extend(auth()->user(), $this->attemptId, $section, $revision, $this->extraMinutes * 60, $this->extensionReason);
        $this->feedback = 'Tambahan waktu dicatat. Timer kandidat akan diperbarui otomatis.';
    }

    public function saveReview(): void
    {
        $this->actor();
        $this->resetValidation();
        abort_unless($this->attemptId, 422);
        app(TechnicalAssessmentService::class)->review(auth()->user(), $this->attemptId, ['status' => $this->reviewStatus, 'notes' => $this->reviewNotes, 'ratings' => $this->ratings]);
        $this->feedback = 'Penilaian assessor tersimpan.';
    }

    protected function getViewData(): array
    {
        $this->actor();

        return [
            'packages' => app(TechnicalAssessmentService::class)->packages(),
            'applications' => $this->eligibleApplications()->whereHas('user', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))->with(['user', 'position', 'period'])->latest()->limit(30)->get(),
            'attempts' => TechnicalAssessmentAttempt::whereHas('application', fn ($query) => $query->whereNull('purged_at')->whereHas('user', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$this->resultSearch.'%')->orWhere('email', 'like', '%'.$this->resultSearch.'%'))))->with(['application.user', 'application.position'])->withCount([
                'activityLogs as tab_hidden_count' => fn ($query) => $query->where('action', 'technical_assessment.activity.tab_hidden'),
                'activityLogs as window_blur_count' => fn ($query) => $query->where('action', 'technical_assessment.activity.window_blur'),
                'activityLogs as fullscreen_exit_count' => fn ($query) => $query->where('action', 'technical_assessment.activity.fullscreen_exit'),
            ])->latest()->paginate(15),
            'selected' => $this->attemptId ? TechnicalAssessmentAttempt::whereHas('application', fn ($query) => $query->whereNull('purged_at'))->with('application.user')->findOrFail($this->attemptId) : null,
            'logs' => $this->attemptId ? TechnicalAssessmentAttempt::findOrFail($this->attemptId)->activityLogs()->with('actor')->latest('id')->limit(100)->get() : collect(),
        ];
    }
}
