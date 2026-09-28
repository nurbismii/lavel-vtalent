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
use App\Services\PsychometricService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Psychometrics extends Page
{
    use WithFileUploads, WithPagination;

    public $correctedPage;

    protected string $view = 'filament.pages.psychometrics';

    protected static ?string $title = 'CFIT 3B';

    protected static ?string $navigationLabel = 'CFIT 3B';

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
        $test = PsychometricTest::create(['title' => 'CFIT Skala 3 Bentuk B · '.now()->format('d/m/Y H:i'), 'sections' => array_map(fn (array $s): array => [...$s, 'seconds' => null], config('psychometrics.sections')), 'answer_key' => []]);
        $this->edit($test->id);
    }

    public function edit(int $id): void
    {
        $this->actor();
        $test = PsychometricTest::findOrFail($id);
        $this->testId = $id;
        $this->reset('questionImage', 'correctedPage', 'imageSection', 'imageQuestion', 'imagePart', 'confirmDelete', 'applicationIds');
        $this->durations = array_map(fn (array $s): mixed => $s['seconds'] ?? '', $test->sections);
        $this->keyText = array_map(fn (array $keys): string => implode(' ', array_map(fn (array $key): string => $key === [] ? '-' : implode(',', $key), $keys)), $test->answer_key ?? []);
        $this->reviewed = false;
        $this->resetValidation();
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
        $this->reset('testId', 'confirmDelete', 'questionImage', 'correctedPage', 'durations', 'keyText', 'reviewed');
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

    public function finalizeExpired(): void
    {
        $this->actor();
        Artisan::call('psychometrics:finalize');
        $this->feedback = 'Status diperbarui. Jawaban yang melewati batas waktu telah dikunci.';
    }

    protected function getViewData(): array
    {
        $this->actor();

        return [
            'tests' => PsychometricTest::latest()->get(),
            'selected' => $this->testId ? PsychometricTest::withCount('attempts')->findOrFail($this->testId) : null,
            'applications' => RecruitmentApplication::whereNull('archived_at')->whereNull('purged_at')->whereHas('user', fn ($q) => $q->where('active', true)->where('role', Role::Candidate)->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))->with(['user', 'position', 'period'])->latest()->limit(30)->get(),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            'periods' => RecruitmentPeriod::orderByDesc('starts_at')->orderByDesc('id')->get(['id', 'name']),
            'attemptCount' => PsychometricAttempt::count(),
            'attempts' => PsychometricAttempt::with(['test', 'application.user', 'application.position', 'application.period'])
                ->when($this->positionFilter !== '', fn ($query) => $query->whereHas('application', fn ($application) => $application->where('position_id', $this->positionFilter)))
                ->when($this->periodFilter !== '', fn ($query) => $query->whereHas('application', fn ($application) => $application->where('recruitment_period_id', $this->periodFilter)))
                ->when(trim($this->resultSearch) !== '', fn ($query) => $query->whereHas('application.user', fn ($user) => $user->where(fn ($candidate) => $candidate
                    ->where('name', 'like', '%'.trim($this->resultSearch).'%')
                    ->orWhere('email', 'like', '%'.trim($this->resultSearch).'%'))))
                ->latest()->orderByDesc('id')->paginate(15),
        ];
    }
}
