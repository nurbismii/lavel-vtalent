<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Psychometrics;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\User;
use App\Services\MbtiService;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MbtiReportTest extends TestCase
{
    use RefreshDatabase;

    private function completedAttempt(string $format = 'likert7'): PsychometricAttempt
    {
        $test = ($format === 'legacy' ? PsychometricTest::factory()->legacyMbti() : PsychometricTest::factory()->mbti())->published()->create();
        $answers = array_fill(1, $test->sections[0]['count'], [$format === 'legacy' ? 'A' : 'D']);
        if ($format === 'likert7') {
            $answers = array_replace($answers, array_fill(1, 4, ['A']));
        }

        return PsychometricAttempt::factory()->create([
            'psychometric_test_id' => $test->id, 'completed_at' => now(), 'answers' => [$answers],
            'result' => app(MbtiService::class)->score($test->sections, [$answers]),
        ]);
    }

    #[TestWith(['likert7'])]
    #[TestWith(['balanced'])]
    #[TestWith(['legacy'])]
    public function test_admin_can_open_all_detailed_report_sections_without_changing_the_saved_result(string $format): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $attempt = $this->completedAttempt($format);
        $attempt->application->user->update(['name' => '<script>nama()</script>']);
        $attempt->test->update(['title' => '<script>paket()</script>']);
        $original = $attempt->fresh()->getAttributes();
        $url = route('psychometrics.mbti-report', $attempt);

        $response = $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])->get($url)
            ->assertOk()->assertSee('Laporan hasil terperinci MBTI')->assertSee('40 / 40 dijawab')
            ->assertSee($attempt->result['type'])->assertSee($attempt->application->position->name)
            ->assertSee($attempt->application->period->name)->assertSee('<script>nama()</script>')
            ->assertDontSee('<script>nama()</script>', false)->assertDontSee('<script>paket()</script>', false)
            ->assertSee('Cetak / Simpan PDF')->assertSee('window.print()', false)
            ->assertDontSee('Cara membaca hasil')->assertSee('12 bagian')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $this->assertCount(12, $document->querySelectorAll('.mbti-report-section'));
        $this->assertTrue($document->querySelector('.mbti-report')->hasAttribute('open'));
        $scoresTarget = '#mbti-report-'.$attempt->id.'-scores';
        $this->assertNull($document->querySelector($scoresTarget));
        $this->assertNull($document->querySelector('a[href="'.$scoresTarget.'"]'));
        foreach (array_diff(array_keys(config('mbti_reports.sections')), ['scores']) as $key) {
            $this->assertNotNull($document->querySelector('#mbti-report-'.$attempt->id.'-'.$key));
        }
        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychometric.report_opened', 'target_id' => $attempt->id, 'actor_id' => $admin->id]);

        Livewire::actingAs($admin)->test(Psychometrics::class)->assertSee($url, false)->assertSee('Laporan terperinci (PDF)');
    }

    #[TestWith(['own_candidate'])]
    #[TestWith(['other_candidate'])]
    #[TestWith(['inactive'])]
    #[TestWith(['password_change'])]
    #[TestWith(['missing_mfa'])]
    public function test_report_rejects_guest_and_unauthorized_users(string $case): void
    {
        $attempt = $this->completedAttempt();
        $url = route('psychometrics.mbti-report', $attempt);
        $this->get($url)->assertRedirect(route('login'));
        $actor = match ($case) {
            'own_candidate' => $attempt->application->user,
            'other_candidate' => User::factory()->create(['role' => Role::Candidate]),
            'inactive' => User::factory()->create(['role' => Role::Admin, 'active' => false]),
            'password_change' => User::factory()->create(['role' => Role::Admin, 'must_change_password' => true]),
            default => User::factory()->create(['role' => Role::Admin]),
        };
        if ($case !== 'missing_mfa') {
            $actor->saveAppAuthenticationSecret('TESTSECRET');
        }
        $response = $this->actingAs($actor)->withSession(['portal_session_version' => $actor->session_version])->get($url);
        if ($case === 'inactive') {
            $response->assertRedirect(route('login'));
        } elseif ($case === 'password_change') {
            $response->assertRedirect(route('password.initial'));
        } else {
            $response->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.report_opened']);
    }

    #[TestWith(['unfinished'])]
    #[TestWith(['incomplete'])]
    #[TestWith(['iq'])]
    #[TestWith(['purged'])]
    public function test_report_is_unavailable_without_a_complete_mbti_result(string $case): void
    {
        $attempt = $this->completedAttempt();
        if ($case === 'unfinished') {
            $attempt->update(['completed_at' => null]);
        } elseif ($case === 'incomplete') {
            $attempt->update(['result' => app(MbtiService::class)->score($attempt->test->sections, [[1 => ['A']]])]);
        } elseif ($case === 'iq') {
            $attempt->test->update(['type' => 'iq']);
        } else {
            $attempt->application->update(['purged_at' => now(), 'active_user_id' => null]);
        }
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $url = route('psychometrics.mbti-report', $attempt);

        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])->get($url)->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.report_opened']);
        Livewire::actingAs($admin)->test(Psychometrics::class)->assertDontSee($url, false);
    }
}
