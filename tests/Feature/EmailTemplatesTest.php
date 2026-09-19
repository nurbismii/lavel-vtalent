<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_template_escapes_html_and_keeps_plain_text_password_exact(): void
    {
        $password = 'Aa<&"123456789!';
        Mail::send(['html' => 'emails.candidate-access', 'text' => 'emails.candidate-access-text'], [
            'name' => 'Nadia <Amelia>',
            'email' => 'candidate@example.test',
            'hrMessage' => "Selamat mengikuti tes.\n<script>alert('x')</script>",
            'temporaryPassword' => $password,
            'expiresAt' => '22 September 2026, 09.00 WITA',
            'loginUrl' => 'https://portal.example.test/login',
        ], fn ($message) => $message->to('candidate@example.test')->subject('Akses kandidat'));
        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('Aa&lt;&amp;&quot;123456789!', $message->getHtmlBody());
        $this->assertStringNotContainsString('<script>', $message->getHtmlBody());
        $this->assertStringContainsString('&lt;script&gt;', $message->getHtmlBody());
        $this->assertStringContainsString('Password sementara: '.$password, $message->getTextBody());
        $this->assertStringContainsString('Tim HR · VDNI', $message->getHtmlBody());
        $this->assertStringContainsString('cid:', $message->getHtmlBody());
        $this->assertCount(1, $message->getAttachments());
    }

    public function test_reset_template_uses_shared_brand_and_keeps_reset_url(): void
    {
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('Buat password baru.', $message->getHtmlBody());
        $this->assertStringContainsString('Tim HR · VDNI', $message->getHtmlBody());
        $this->assertStringContainsString('cid:', $message->getHtmlBody());
        $this->assertStringContainsString('/reset-password/', $message->getTextBody());
        $this->assertStringContainsString('Password Anda tidak akan berubah.', $message->getTextBody());
    }
}
