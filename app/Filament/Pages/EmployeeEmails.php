<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\EmailBroadcast;
use App\Models\EmailBroadcastDelivery;
use App\Models\User;
use App\Services\EmailBroadcastService;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class EmployeeEmails extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.employee-emails';

    protected static ?string $title = 'Blast Email Kandidat';

    protected static string|\UnitEnum|null $navigationGroup = 'Komunikasi kandidat';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public array $data = [];

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public string $submissionKey;

    #[Locked]
    public ?int $broadcastId = null;

    public string $statusFilter = '';

    public string $feedback = '';

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return (bool) ($user && $user->active && $user->role === Role::Admin && ! $user->must_change_password && $user->getAppAuthenticationSecret());
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->submissionKey = (string) Str::uuid();
        $this->form->fill();
        $this->broadcastId = EmailBroadcast::latest('id')->value('id');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Textarea::make('recipients')->label('Email penerima')
                ->placeholder("kandidat.satu@perusahaan.com\nkandidat.dua@perusahaan.com")
                ->helperText('Copy kolom email dari Excel, lalu paste di sini. Maksimal 100 alamat unik; duplikat otomatis dihapus. Penerima tidak perlu memiliki akun V Talent.')
                ->rows(6)->required()->maxLength(30000),
            TextInput::make('subject')->label('Subjek email')->placeholder('Contoh: Informasi pembaruan data kandidat')
                ->required()->maxLength(200),
            RichEditor::make('body')->label('Isi pesan')->placeholder('Tuliskan informasi dan arahan untuk kandidat…')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'], ['h2', 'h3'],
                    ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo'],
                ])->required()->maxLength(50000),
        ]);
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data.')) {
            $this->preview = null;
        }
        if ($property === 'statusFilter') {
            $this->resetPage('deliveryPage');
        }
    }

    public function previewEmail(): void
    {
        $this->authorizeAdmin();
        $this->feedback = '';
        $this->resetValidation();
        if (is_string($this->data['body'] ?? null)) {
            $this->data['body'] = Str::sanitizeHtml($this->data['body']);
        }
        $this->preview = app(EmailBroadcastService::class)->prepare($this->form->getState());
    }

    public function editMessage(): void
    {
        $this->authorizeAdmin();
        $this->preview = null;
    }

    public function send(): void
    {
        $actor = $this->authorizeAdmin();
        if (! $this->preview) {
            throw ValidationException::withMessages(['delivery' => 'Periksa pratinjau pesan dan daftar penerima sebelum mengirim.']);
        }
        $broadcast = app(EmailBroadcastService::class)->queue($actor, $this->preview, $this->submissionKey);
        $count = $broadcast->deliveries()->count();
        $this->broadcastId = $broadcast->id;
        $this->submissionKey = (string) Str::uuid();
        $this->preview = null;
        $this->form->fill();
        $this->statusFilter = '';
        $this->resetPage('deliveryPage');
        $this->resetPage('broadcastPage');
        $this->feedback = "$count email disiapkan untuk dikirim. Pantau status setiap penerima di bawah.";
    }

    public function selectBroadcast(int $id): void
    {
        $this->authorizeAdmin();
        $this->broadcastId = EmailBroadcast::findOrFail($id)->id;
        $this->statusFilter = '';
        $this->resetPage('deliveryPage');
    }

    public function retryDelivery(int $id): void
    {
        app(EmailBroadcastService::class)->retry($this->authorizeAdmin(), $id);
        $this->feedback = 'Email gagal dijadwalkan ulang. Pantau status pengirimannya.';
    }

    private function authorizeAdmin(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user()->fresh();
    }

    protected function getViewData(): array
    {
        $this->authorizeAdmin();

        return [
            'broadcasts' => EmailBroadcast::withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])->latest('id')->paginate(5, pageName: 'broadcastPage'),
            'selectedBroadcast' => $this->broadcastId ? EmailBroadcast::find($this->broadcastId) : null,
            'deliveries' => EmailBroadcastDelivery::where('email_broadcast_id', $this->broadcastId)
                ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
                ->orderBy('id')->paginate(15, pageName: 'deliveryPage'),
        ];
    }
}
