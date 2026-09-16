<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_can_request_email_reset_password_and_login(): void
    {
        $user = User::factory()->create(['email' => 'candidate@example.test', 'must_change_password' => true]);
        $this->get(route('password.request'))->assertOk();
        $this->from(route('password.request'))->post(route('password.email'), ['email' => strtoupper($user->email)])
            ->assertRedirect(route('password.request'))->assertSessionHasNoErrors();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        preg_match('~https?://[^\s<>]+/reset-password/[^\s<>]+~', $message->getTextBody(), $matches);
        $this->assertNotEmpty($matches);
        $url = $matches[0];
        $this->get($url)->assertOk()->assertSee($user->email);
        $token = basename(parse_url($url, PHP_URL_PATH));
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'New-password-12345', 'password_confirmation' => 'New-password-12345'];
        $this->post(route('password.update'), $payload)->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_reset']);
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $user->email, 'password' => $payload['password']])->assertRedirect(route('candidate.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_inactive_and_admin_accounts_receive_generic_response_without_email(): void
    {
        $inactive = User::factory()->create(['active' => false]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        foreach (['missing@example.test', $inactive->email, $admin->email] as $email) {
            $this->post(route('password.email'), ['email' => $email])->assertSessionHas('status', 'Jika email terdaftar dan akun aktif, instruksi reset akan dikirim.');
        }
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_expired_token_cannot_change_password(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'New-password-12345', 'password_confirmation' => 'New-password-12345'])->assertSessionHasErrors('email');
        $this->assertSame($originalPassword, $user->fresh()->password);
    }
}
