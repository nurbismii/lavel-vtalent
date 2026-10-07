<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Position;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Services\PsychometricResultExportService;
use App\Services\PsychometricService;
use App\Services\RecruitmentService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Psychometrics extends Page
{
    use WithFileUploads, WithPagination;

    public $correctedPage;

    protected string $view = 'filament.pages.psychometrics';

    protected static ?string $title = 'TES IQ';

    protected static ?string $navigationLabel = 'TES IQ';

    protected static string|\UnitEnum|null $navigationGroup = 'Psikotes';

    public $questionImage;

    public int $imageSection = 0;

    public int $imageQuestion = 1;

    public string $imagePart = 'stem';

    public bool $confirmDelete = false;

    #[Locked]
    public ?int $deletingAttemptId = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    #[Locked]
    public ?int $testId = null;

    public string $packageTitle = '';

    public array $durations = [];

    public array $keyText = [];

    public bool $reviewed = false;

    public string $feedback = '';

    public array $applicationIds = [];

    public string $opensAt = '';

    public string $deadline = '';

    public string $search = '';

    public string $resultSearch = '';

    public string $positionFilter = '';

    public string $periodFilter = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['resultSearch', 'positionFilter', 'periodFilter'], true)) {
            $this->resetPage();
        }
    }

    public function resetResultFilters(): void
    {
        $this->actor();
        $this->reset('resultSearch', 'positionFilter', 'periodFilter');
        $this->resetPage();
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return PsychometricService::available() && $user?->active && $user->role === Role::Admin && ! $user->must_change_password;
    }

    public function mount(): void
    {
        $this->actor();
    }

    private function actor(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function createDraft(): void
    {
        $this->actor();
        $test = PsychometricTest::create(['title' => 'TES IQ · '.now()->format('d/m/Y H:i'), 'sections' => array_map(fn (array $s): array => [...$s, 'seconds' => null], config('psychometrics.sections')), 'answer_key' => []]);
        $this->edit($test->id);
    }

    public function edit(int $id): void
    {
        $this->actor();
        $test = PsychometricTest::findOrFail($id);
        $this->testId = $id;
        $this->packageTitle = $test->title;
        $this->reset('questionImage', 'correctedPage', 'imageSection', 'imageQuestion', 'imagePart', 'confirmDelete', 'applicationIds');
        $this->durations = array_map(fn (array $s): mixed => $s['seconds'] ?? '', $test->sections);
        $this->keyText = array_map(fn (array $keys): string => implode(' ', array_map(fn (array $key): string => $key === [] ? '-' : implode(',', $key), $keys)), $test->answer_key ?? []);
        $this->reviewed = false;
        $this->resetValidation();
    }

    public function renamePackage(): void
    {
        $this->actor();
        abort_unless($this->testId, 422);
        $this->reset('feedback');
        $this->packageTitle = trim($this->packageTitle);
        $this->validate(['packageTitle' => ['required', 'string', 'max:255']], [
            'packageTitle.required' => 'Nama paket tes wajib diisi.',
            'packageTitle.max' => 'Nama paket tes maksimal 255 karakter.',
        ]);

        DB::transaction(function (): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($this->testId);
            if ($test->title === $this->packageTitle) {
                return;
            }
            $previousTitle = $test->title;
            $test->update(['title' => $this->packageTitle]);
            AuditLog::record('psychometric.renamed', $test, auth()->user(), metadata: [
                'previous_title' => $previousTitle,
                'title' => $test->title,
            ]);
        });

        $this->feedback = 'Nama paket tes berhasil disimpan.';
    }

    public function uploadCorrection(): void
    {
        $this->actor();
        $this->validate(['correctedPage' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=10000,max_height=10000']]);
        DB::transaction(function (): void {
            $test = PsychometricTest::lockForUpdate()->findOrFail($this->testId);
            abort_if($test->published_at, 409);
            $path = $this->correctedPage->store('psychometrics/corrections', 'private');
            $test->update(['corrected_page_path' => $path]);
            AuditLog::record('psychometric.source_corrected', $test, auth()->user());
        });
        $this->reset('correctedPage');
        $this->reviewed = false;
        $this->feedback = 'Halaman pengganti disimpan. Periksa pratinjau sebelum menerbitkan.';
    }

    public function uploadQuestionImage(): void
    {
        $this->actor();
        $this->validate(['questionImage' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=10000,max_height=10000']]);
        abort_unless($this->testId, 422);
        app(PsychometricService::class)->replaceImage(auth()->user(), $this->testId, $this->imageSection, $this->imageQuestion, $this->imagePart, $this->questionImage);
        $this->reset('questionImage');
        $this->reviewed = false;
        $this->feedback = 'Gambar soal berhasil diganti. Periksa pratinjau dan sesuaikan kunci jawaban sebelum menerbitkan.';
    }

    public function updatedImageSection(): void
    {
        $this->reset('imageQuestion', 'imagePart', 'questionImage');
    }

    public function updatedImageQuestion(): void
    {
        $this->reset('questionImage');
    }

    public function updatedImagePart(): void
    {
        $this->reset('questionImage');
    }

    public function confirmAttemptDeletion(int $id): void
    {
        $this->actor();
        $this->deletingAttemptId = PsychometricAttempt::findOrFail($id)->id;
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
        app(PsychometricService::class)->deleteAttempt(auth()->user(), $this->deletingAttemptId);
        $this->reset('deletingAttemptId');
        $this->resetPage();
        $this->feedback = 'Peserta berhasil dihapus dari tes beserta jawaban dan hasilnya. Akun dan lamaran tetap tersedia.';
    }

    public function deletePackage(): void
    {
        $this->actor();
        abort_unless($this->testId && $this->confirmDelete, 422);
        app(PsychometricService::class)->deletePackage(auth()->user(), $this->testId);
        $this->reset('testId', 'packageTitle', 'confirmDelete', 'questionImage', 'correctedPage', 'durations', 'keyText', 'reviewed');
        $this->feedback = 'Paket tes berhasil dihapus.';
    }

    public function save(bool $publish = false): void
    {
        $this->actor();
        abort_unless($this->testId, 422);
        app(PsychometricService::class)->configure(auth()->user(), $this->testId, $this->durations, $this->keyText, $publish, $this->reviewed);
        $this->feedback = $publish ? 'Tes diterbitkan. Konfigurasi telah dikunci.' : 'Draf berhasil disimpan.';
        $this->edit($this->testId);
    }

    public function assign(): void
    {
        $this->actor();
        $this->validate(['applicationIds' => ['required', 'array', 'min:1', 'max:100'], 'applicationIds.*' => ['required', 'integer', 'distinct'], 'opensAt' => ['required', 'date'], 'deadline' => ['required', 'date', 'after:opensAt']]);
        abort_unless($this->testId, 422);
        $zone = AppSetting::valueFor('timezone');
        $opensAt = Carbon::parse($this->opensAt, $zone)->utc()->toDateTimeString();
        $deadline = Carbon::parse($this->deadline, $zone)->utc()->toDateTimeString();
        $created = DB::transaction(function () use ($opensAt, $deadline): int {
            $created = 0;
            foreach ($this->applicationIds as $applicationId) {
                $attempt = app(PsychometricService::class)->assign(auth()->user(), $this->testId, (int) $applicationId, $opensAt, $deadline);
                $created += (int) $attempt->wasRecentlyCreated;
            }

            return $created;
        });
        $skipped = count($this->applicationIds) - $created;
        $this->reset('applicationIds');
        $this->feedback = "{$created} penugasan berhasil dibuat. {$skipped} penugasan yang sudah ada dilewati tanpa perubahan.";
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
        $this->feedback = count($this->applicationIds).' lamaran kandidat dipilih untuk tes IQ.';
    }

    public function finalizeExpired(): void
    {
        $this->actor();
        Artisan::call('psychometrics:finalize');
        $this->feedback = 'Status diperbarui. Jawaban yang melewati batas waktu telah dikunci.';
    }

    /** @return array{search: string, position: string, period: string} */
    private function resultFilters(): array
    {
        return ['search' => $this->resultSearch, 'position' => $this->positionFilter, 'period' => $this->periodFilter];
    }

    public function exportResults(): StreamedResponse
    {
        $this->actor();
        $this->validate(['resultSearch' => ['string', 'max:255'], 'positionFilter' => ['nullable', 'integer'], 'periodFilter' => ['nullable', 'integer']]);
        $actor = auth()->user();
        $filters = $this->resultFilters();

        return response()->streamDownload(function () use ($actor, $filters): void {
            app(PsychometricResultExportService::class)->write($actor, $filters, 'php://output');
        }, 'hasil-psikotes-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }

    protected function getViewData(): array
    {
        $this->actor();

        return [
            'tests' => PsychometricTest::latest()->get(),
            'selected' => $this->testId ? PsychometricTest::withCount('attempts')->findOrFail($this->testId) : null,
            'applications' => $this->eligibleApplications()->whereHas('user', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))->with(['user', 'position', 'period'])->latest()->limit(30)->get(),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            'periods' => RecruitmentPeriod::orderByDesc('starts_at')->orderByDesc('id')->get(['id', 'name']),
            'attemptCount' => PsychometricAttempt::count(),
            'attempts' => app(PsychometricResultExportService::class)->filtered($this->resultFilters())
                ->with(['test', 'application.user', 'application.position', 'application.period'])
                ->withCount([
                    'activityLogs as tab_hidden_count' => fn ($query) => $query->where('action', 'psychometric.activity.tab_hidden'),
                    'activityLogs as window_blur_count' => fn ($query) => $query->where('action', 'psychometric.activity.window_blur'),
                    'activityLogs as fullscreen_exit_count' => fn ($query) => $query->where('action', 'psychometric.activity.fullscreen_exit'),
                ])
                ->latest()->orderByDesc('id')->paginate(15),
        ];
    }
}
