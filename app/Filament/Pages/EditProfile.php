<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\RateLimiter;

class EditProfile extends BaseEditProfile
{
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        return parent::getMultiFactorAuthenticationContentComponent()
            ?->footer([Actions::make([$this->addAppAuthenticationDeviceAction()])]);
    }

    public function addAppAuthenticationDeviceAction(): Action
    {
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];

        return Action::make('addAppAuthenticationDevice')
            ->label('Tambah perangkat authenticator')
            ->icon('heroicon-o-qr-code')
            ->link()
            ->authorize($this->canAddAuthenticatorDevice(...))
            ->modalWidth(Width::Medium)
            ->modalHeading('Tambah perangkat authenticator')
            ->modalDescription('Verifikasi password dan kode dari perangkat lama untuk menampilkan QR. Jika kode baru dipakai untuk login, tunggu kode berikutnya.')
            ->schema(fn (): array => [
                TextInput::make('current_password')
                    ->label('Password saat ini')
                    ->password()
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
                ...$provider->getChallengeFormComponents($this->getUser()->fresh()),
            ])
            ->modalSubmitActionLabel('Verifikasi & tampilkan QR')
            ->modalCancelActionLabel('Tutup')
            ->beforeFormValidated(function (Action $action): void {
                $key = 'authenticator-add-device:'.Filament::auth()->id();

                if (RateLimiter::tooManyAttempts($key, 5)) {
                    Notification::make()->title('Terlalu banyak percobaan. Coba lagi dalam satu menit.')->danger()->send();
                    $action->halt();
                }

                RateLimiter::hit($key);
            })
            ->action(function (): void {
                $user = $this->getUser()->fresh();
                AuditLog::record('authenticator.qr_revealed', $user, $user);

                $this->replaceMountedAction('showAppAuthenticationQrCode', [
                    'verification' => encrypt([
                        'user_id' => $user->id,
                        'secret_hash' => hash('sha256', $user->getAppAuthenticationSecret()),
                        'expires_at' => now()->addMinutes(2)->timestamp,
                    ]),
                ]);
            });
    }

    public function showAppAuthenticationQrCodeAction(): Action
    {
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];

        return Action::make('showAppAuthenticationQrCode')
            ->authorize(fn (array $arguments): bool => $this->canAddAuthenticatorDevice() && $this->getVerifiedAppAuthenticationSecret($arguments) !== null)
            ->modalWidth(Width::Medium)
            ->modalHeading('Pindai di perangkat tambahan')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Selesai')
            ->schema(function (array $arguments) use ($provider): array {
                $secret = $this->getVerifiedAppAuthenticationSecret($arguments);

                return $secret === null ? [] : [
                    Text::make('Pindai QR ini di perangkat tambahan. Perangkat lama tetap aktif. Jangan bagikan QR ini; semua perangkat yang memindainya memiliki akses MFA yang sama.'),
                    Image::make(
                        $provider->generateQrCodeDataUri($secret),
                        'QR authenticator untuk perangkat tambahan',
                    )->imageHeight('12rem')->alignCenter(),
                ];
            });
    }

    protected function canAddAuthenticatorDevice(): bool
    {
        $user = Filament::auth()->user()?->fresh();

        return $user && $user->canAccessPanel(Filament::getCurrentPanel()) && filled($user->getAppAuthenticationSecret());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function getVerifiedAppAuthenticationSecret(array $arguments): ?string
    {
        if (! is_string($arguments['verification'] ?? null)) {
            return null;
        }

        try {
            $verification = decrypt($arguments['verification']);
        } catch (DecryptException) {
            return null;
        }

        $user = $this->getUser()->fresh();
        $secret = $user->getAppAuthenticationSecret();

        return is_array($verification)
            && ($verification['user_id'] ?? null) === $user->id
            && ($verification['expires_at'] ?? 0) > now()->timestamp
            && ($verification['secret_hash'] ?? null) === hash('sha256', $secret ?? '')
                ? $secret
                : null;
    }
}
