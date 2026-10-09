<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\EditProfile;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class MultiDeviceAppAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_reveals_the_existing_qr_only_after_password_and_otp_verification(): void
    {
        $admin = $this->createAdmin();
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];
        $encryptedSecret = $admin->getRawOriginal('app_authentication_secret');
        $page = Livewire::actingAs($admin)->test(EditProfile::class);

        $page->mountAction($this->addDeviceAction())
            ->call('forceRender')
            ->assertSee('Password saat ini')
            ->assertDontSee('QR authenticator untuk perangkat tambahan')
            ->setActionData(['current_password' => 'password', 'code' => $provider->getCurrentCode($admin)])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionMounted('showAppAuthenticationQrCode')
            ->call('forceRender')
            ->assertSee('QR authenticator untuk perangkat tambahan')
            ->assertSee($provider->generateQrCodeDataUri(self::SECRET), escape: false)
            ->assertSet('mountedActions.0.data.current_password', null);

        $this->assertSame(self::SECRET, $admin->fresh()->getAppAuthenticationSecret());
        $this->assertSame($encryptedSecret, $admin->fresh()->getRawOriginal('app_authentication_secret'));
        $audit = AuditLog::query()->sole();
        $this->assertSame('authenticator.qr_revealed', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertEmpty($audit->metadata);
    }

    public function test_invalid_password_does_not_reveal_the_qr(): void
    {
        $admin = $this->createAdmin();
        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];

        Livewire::actingAs($admin)->test(EditProfile::class)
            ->callAction($this->addDeviceAction(), ['current_password' => 'wrong-password', 'code' => $provider->getCurrentCode($admin)])
            ->assertHasActionErrors(['current_password'])
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(self::SECRET, $admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_missing_or_invalid_codes_do_not_reveal_the_qr(): void
    {
        $admin = $this->createAdmin();
        $page = Livewire::actingAs($admin)->test(EditProfile::class)->mountAction($this->addDeviceAction());

        $page->callMountedAction()->assertHasActionErrors(['current_password', 'code'])
            ->setActionData(['current_password' => 'password', 'code' => 'badOTP'])
            ->callMountedAction()->assertHasActionErrors(['code'])
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_profile_route_shows_the_add_device_action_and_requires_login(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
        $admin = $this->createAdmin();

        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])
            ->get('/admin/profile')->assertSee('Tambah perangkat authenticator');
    }

    public function test_five_failed_attempts_block_further_verification_even_with_changed_arguments(): void
    {
        $admin = $this->createAdmin();
        $page = Livewire::actingAs($admin)->test(EditProfile::class)
            ->mountAction($this->addDeviceAction())
            ->setActionData(['current_password' => 'password', 'code' => 'badOTP']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $page->callMountedAction(['attempt' => $attempt])->assertHasActionErrors(['code']);
        }

        $page->setActionData(['code' => Filament::getMultiFactorAuthenticationProviders()['app']->getCurrentCode($admin)])
            ->callMountedAction(['attempt' => 6])
            ->assertNotified('Terlalu banyak percobaan. Coba lagi dalam satu menit.')
            ->call('forceRender')->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith([null])]
    #[TestWith(['forged-verification'])]
    #[TestWith([['user_id' => 1]])]
    public function test_qr_cannot_be_opened_without_valid_verification(mixed $verification): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)->test(EditProfile::class)
            ->call('mountAction', 'showAppAuthenticationQrCode', ['verification' => $verification])
            ->assertActionNotMounted()
            ->call('forceRender')->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_verification_expires_after_two_minutes(): void
    {
        $this->freezeTime();
        $page = $this->revealQr($this->createAdmin());
        $arguments = $page->get('mountedActions.0.arguments');
        $page->unmountAction();
        $this->travel(2)->minutes();

        $page->call('mountAction', 'showAppAuthenticationQrCode', $arguments)
            ->assertActionNotMounted()->call('forceRender')
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_verification_cannot_be_reused_by_another_admin(): void
    {
        $page = $this->revealQr($this->createAdmin());
        $arguments = $page->get('mountedActions.0.arguments');
        $otherAdmin = $this->createAdmin();

        Livewire::actingAs($otherAdmin)->test(EditProfile::class)
            ->call('mountAction', 'showAppAuthenticationQrCode', $arguments)
            ->assertActionNotMounted()->call('forceRender')
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_rotating_the_secret_invalidates_previous_qr_verification(): void
    {
        $admin = $this->createAdmin();
        $page = $this->revealQr($admin);
        $arguments = $page->get('mountedActions.0.arguments');
        $page->unmountAction();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXQ');

        $page->call('mountAction', 'showAppAuthenticationQrCode', $arguments)
            ->assertActionNotMounted()->call('forceRender')
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[TestWith(['candidate', true, false, true])]
    #[TestWith(['admin', false, false, true])]
    #[TestWith(['admin', true, true, true])]
    #[TestWith(['admin', true, false, false])]
    public function test_accounts_without_panel_access_or_mfa_cannot_add_a_device(string $role, bool $active, bool $mustChangePassword, bool $hasMfa): void
    {
        $admin = User::factory()->create(['role' => $role, 'active' => $active, 'must_change_password' => $mustChangePassword]);
        if ($hasMfa) {
            $admin->saveAppAuthenticationSecret(self::SECRET);
        }
        $arguments = ['verification' => encrypt(['user_id' => $admin->id, 'secret_hash' => hash('sha256', self::SECRET), 'expires_at' => now()->addMinute()->timestamp])];

        Livewire::actingAs($admin)->test(EditProfile::class)
            ->mountAction($this->addDeviceAction())->assertActionNotMounted()
            ->call('mountAction', 'showAppAuthenticationQrCode', $arguments)
            ->assertActionNotMounted()->call('forceRender')
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_an_otp_used_to_reveal_the_qr_cannot_be_used_again(): void
    {
        $admin = $this->createAdmin();
        $code = Filament::getMultiFactorAuthenticationProviders()['app']->getCurrentCode($admin);
        $page = $this->revealQr($admin)->unmountAction();

        $page->callAction($this->addDeviceAction(), ['current_password' => 'password', 'code' => $code])
            ->assertHasActionErrors(['code'])->call('forceRender')
            ->assertDontSee('QR authenticator untuk perangkat tambahan');

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_two_authenticators_with_the_same_secret_can_each_complete_login(): void
    {
        $admin = $this->createAdmin();
        $firstDevice = new Google2FA;
        $secondDevice = new Google2FA;
        $period = $firstDevice->getTimestamp();
        $firstCode = $firstDevice->oathTotp(self::SECRET, $period);
        $secondCode = $secondDevice->oathTotp(self::SECRET, $period + 1);

        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')->assertHasNoErrors()
            ->set('data.multiFactor.app.code', $firstCode)
            ->call('authenticate')->assertHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        Filament::auth()->logout();

        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')->assertHasNoErrors()
            ->set('data.multiFactor.app.code', $secondCode)
            ->call('authenticate')->assertHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(self::SECRET, $admin->fresh()->getAppAuthenticationSecret());
    }

    private function revealQr(User $admin): Testable
    {
        return Livewire::actingAs($admin)->test(EditProfile::class)
            ->callAction($this->addDeviceAction(), [
                'current_password' => 'password',
                'code' => Filament::getMultiFactorAuthenticationProviders()['app']->getCurrentCode($admin),
            ])->assertHasNoActionErrors()->assertActionMounted('showAppAuthenticationQrCode');
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret(self::SECRET);

        return $admin;
    }

    private function addDeviceAction(): TestAction
    {
        return TestAction::make('addAppAuthenticationDevice');
    }
}
