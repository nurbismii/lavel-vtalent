<?php

namespace App\Livewire\Candidate;

use App\Enums\Role;
use App\Enums\SubmissionType;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Models\UploadedFile;
use App\Services\SubmissionService;
use App\Services\UploadService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class SubmissionForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $submissionId;

    #[Locked]
    public ?int $versionId = null;

    public string $notes = '';

    public array $links = [];

    public array $attachments = [];

    public $upload;

    public string $purpose = 'portfolio_main';

    public bool $confirming = false;

    public bool $dirty = false;

    public string $feedback = '';

    public function mount(Submission $submission): void
    {
        abort_unless(auth()->user()->role === Role::Candidate, 403);
        Gate::authorize('view', $submission);
        $this->submissionId = $submission->id;
        $this->purpose = $submission->type === SubmissionType::Portfolio ? 'portfolio_main' : 'technical_result';
        $version = $submission->versions()->where('status', 'draft')->first() ?? $submission->currentVersion;
        if (! $version && $submission->editable()) {
            $version = app(SubmissionService::class)->draft($submission, auth()->user());
        }
        if ($version) {
            $this->hydrateVersion($version);
        }
    }

    private function submission(): Submission
    {
        $submission = Submission::with('application.position', 'application.period')->findOrFail($this->submissionId);
        Gate::authorize('view', $submission);
        abort_unless(auth()->user()->role === Role::Candidate, 403);

        return $submission;
    }

    private function hydrateVersion(SubmissionVersion $version): void
    {
        $this->versionId = $version->id;
        $this->notes = $version->notes ?? '';
        $this->links = $version->links ?? [];
        $this->attachments = $version->attachments()->orderBy('sort_order')->get()->sortBy(fn ($a) => $a->purpose === 'portfolio_main' ? 0 : 1)->map(fn ($a) => ['id' => $a->uploaded_file_id, 'description' => $a->description ?? ''])->values()->all();
    }

    public function updated(string $property): void
    {
        if ($property === 'notes' || str_starts_with($property, 'links') || str_starts_with($property, 'attachments')) {
            $this->dirty = true;
            $this->confirming = false;
        }
    }

    public function updatedUpload(): void
    {
        if (! $this->upload) {
            return;
        }
        $file = app(UploadService::class)->store($this->submission(), auth()->user(), $this->upload, $this->purpose);
        $this->reset('upload');
        $this->feedback = config('submissions.scan_enabled')
            ? 'Unggahan diterima. Tunggu pemeriksaan keamanan, lalu pilih Gunakan file.'
            : 'File berhasil disimpan. Pilih Gunakan file, lalu Periksa & kirim final untuk menyelesaikan pengumpulan.';
    }

    public function useFile(int $id): void
    {
        $submission = $this->submission();
        Gate::authorize('update', $submission);
        $file = UploadedFile::where('submission_id', $submission->id)->where('uploader_id', auth()->id())->findOrFail($id);
        abort_unless($file->scan_status->available(), 422);
        if ($file->purpose === 'portfolio_main') {
            $mainIds = UploadedFile::where('submission_id', $submission->id)->where('purpose', 'portfolio_main')->pluck('id')->all();
            $this->attachments = array_values(array_filter($this->attachments, fn ($a) => ! in_array($a['id'], $mainIds, true)));
        }
        if (! in_array($id, array_column($this->attachments, 'id'), true)) {
            if ($file->purpose === 'portfolio_main') {
                array_unshift($this->attachments, ['id' => $id, 'description' => '']);
            } else {
                $this->attachments[] = ['id' => $id, 'description' => ''];
            }
        }
        $this->dirty = true;
        $this->confirming = false;
    }

    public function removeFile(int $id): void
    {
        Gate::authorize('update', $this->submission());
        $this->attachments = array_values(array_filter($this->attachments, fn ($a) => (int) $a['id'] !== $id));
        $this->dirty = true;
        $this->confirming = false;
    }

    public function discard(int $id): void
    {
        $file = UploadedFile::where('submission_id', $this->submission()->id)->findOrFail($id);
        app(UploadService::class)->discard($file, auth()->user());
    }

    public function retry(int $id): void
    {
        $file = UploadedFile::where('submission_id', $this->submission()->id)->findOrFail($id);
        app(UploadService::class)->retry($file, auth()->user());
    }

    public function addLink(): void
    {
        Gate::authorize('update', $this->submission());
        if (count($this->links) < 5) {
            $this->links[] = ['label' => '', 'url' => ''];
            $this->dirty = true;
        }
    }

    public function removeLink(int $index): void
    {
        Gate::authorize('update', $this->submission());
        unset($this->links[$index]);
        $this->links = array_values($this->links);
        $this->dirty = true;
    }

    private function data(): array
    {
        return ['notes' => $this->notes, 'links' => $this->links, 'attachments' => $this->attachments];
    }

    public function save(): void
    {
        $this->validate(['notes' => 'nullable|string|max:5000', 'links' => 'array|max:5', 'links.*.label' => 'required|string|max:120', 'links.*.url' => 'required|url:http,https|max:2048', 'attachments.*.description' => 'nullable|string|max:500']);
        $version = app(SubmissionService::class)->save($this->submission(), auth()->user(), $this->versionId, $this->data());
        $this->hydrateVersion($version);
        $this->dirty = false;
        $this->feedback = 'Draf berhasil disimpan. Belum dikirim final.';
    }

    public function review(): void
    {
        $this->save();
        $this->confirming = true;
    }

    public function finalize(): void
    {
        $version = app(SubmissionService::class)->save($this->submission(), auth()->user(), $this->versionId, $this->data(), true);
        $this->dirty = false;
        $this->confirming = false;
        $this->hydrateVersion($version);
        $this->feedback = 'Pengumpulan final berhasil. Tanda terima tersedia di bawah.';
    }

    public function render(): mixed
    {
        $submission = $this->submission();
        $version = $this->versionId ? $submission->versions()->with('attachments.file')->findOrFail($this->versionId) : null;
        $selected = UploadedFile::where('submission_id', $submission->id)->whereIn('id', array_column($this->attachments, 'id'))->get()->keyBy('id');
        $staged = UploadedFile::where('submission_id', $submission->id)->whereDoesntHave('attachments')->whereNotIn('id', array_column($this->attachments, 'id'))->latest()->get();

        return view('livewire.candidate.submission-form', compact('submission', 'version', 'selected', 'staged'))->layout('components.layouts.portal');
    }
}
