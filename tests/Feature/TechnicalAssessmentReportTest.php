<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\TechnicalAssessments;
use App\Models\AuditLog;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\User;
use App\Services\TechnicalAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TechnicalAssessmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_report_shows_assigned_questions_answers_and_scores_for_both_completed_forms(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $packages = app(TechnicalAssessmentService::class)->packages();
        $packages['A']['sections'][0]['questions'][0]['prompt'] = 'Soal asli saat penugasan kandidat';
        $packages['A']['assessor_material'] = [['text' => 'KUNCI RAHASIA ASSESSOR']];
        $formA = TechnicalAssessmentAttempt::factory()->create([
            'package' => $packages['A'], 'completed_at' => now(), 'answers' => [0 => ['A01' => 'C']],
            'scores' => ['items' => ['A01' => 1, 'A02' => 0], 'Verbal' => 1, 'IPP pilot' => 3.75, 'E' => null, 'Perlu verifikasi' => ['D03', 'D04']],
        ]);
        $formA->application->user->update(['name' => '<script>nama()</script>']);
        $formB = TechnicalAssessmentAttempt::factory()->create([
            'recruitment_application_id' => $formA->recruitment_application_id, 'form' => 'B', 'package' => $packages['B'], 'version' => $packages['B']['version'],
            'completed_at' => now(), 'answers' => [0 => ['B01' => 'B', 'process' => "<script>jawaban()</script>\nPenjelasan kandidat."], 1 => ['priority_1_priority' => '0', 'revised_decision' => 'Rekening master terverifikasi.']],
            'scores' => ['items' => ['B01' => 1], 'Penalaran objektif' => 1], 'conditions' => ['technical_conditions' => 'Tidak ada gangguan teknis.'],
            'review' => ['status' => 'Kemampuan terdemonstrasi', 'notes' => 'Bukti proses telah diverifikasi.', 'ratings' => ['Kontrol risiko' => 0]],
        ]);
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $formA->recruitment_application_id, 'form' => 'B', 'version' => 'VERSI BELUM SELESAI']);
        TechnicalAssessmentAttempt::factory()->create(['completed_at' => now(), 'version' => 'KANDIDAT LAIN']);
        $originalAnswers = $formB->getRawOriginal('answers');

        $response = $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])
            ->get(route('technical-assessments.report', $formA->recruitment_application_id));

        $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Cetak / Simpan PDF')
            ->assertSee('<script>nama()</script>')->assertDontSee('<script>nama()</script>', false)
            ->assertSee('<script>jawaban()</script>')->assertDontSee('<script>jawaban()</script>', false)
            ->assertSee('Soal asli saat penugasan kandidat')->assertSee('C - '.$packages['A']['sections'][0]['questions'][0]['options']['C'])
            ->assertSeeInOrder(['Soal asli saat penugasan kandidat', 'Jawaban kandidat', 'Poin: 1 / 1', 'Ringkasan poin Form A', 'IPP pilot', '3.75', 'Form B', 'Ringkasan poin Form B'])
            ->assertSee('Belum dijawab')->assertSee('Item belum lengkap')->assertSee('D03, D04')
            ->assertSee('Dokumen A Permintaan Pembayaran')->assertSee('INV-381')->assertSee('Pada pukul 14.05')
            ->assertSee('Rekening master terverifikasi.')->assertSee('Kontrol risiko (assessor)')->assertSee('0 / 4')
            ->assertSee('Bukti proses telah diverifikasi.')->assertSee('Tidak ada gangguan teknis.')
            ->assertDontSee('VERSI BELUM SELESAI')->assertDontSee('KANDIDAT LAIN')->assertDontSee('KUNCI RAHASIA ASSESSOR');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($originalAnswers, $formB->fresh()->getRawOriginal('answers'));
        $audit = AuditLog::where('action', 'technical_assessment.report_opened')->sole();
        $this->assertSame([$formA->id, $formB->id], $audit->metadata['attempt_ids']);
        $this->assertSame($admin->id, $audit->actor_id);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $formA->id)
            ->assertSee('PDF kandidat (Form A + B)')->assertSee(route('technical-assessments.report', $formA->recruitment_application_id), false);
    }

    public function test_report_rejects_missing_purged_and_unfinished_results(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $attempt = TechnicalAssessmentAttempt::factory()->create();
        $url = route('technical-assessments.report', $attempt->recruitment_application_id);
        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version]);

        $this->get($url)->assertNotFound();
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $attempt->recruitment_application_id, 'form' => 'A2', 'completed_at' => now()]);
        $this->get($url)->assertNotFound();
        $attempt->update(['completed_at' => now()]);
        $attempt->application->update(['purged_at' => now()]);
        $this->get($url)->assertNotFound();
        $this->get(route('technical-assessments.report', 999999))->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.report_opened']);
    }

    #[TestWith(['candidate'])]
    #[TestWith(['inactive'])]
    #[TestWith(['password_change'])]
    #[TestWith(['missing_mfa'])]
    public function test_report_rejects_unauthorized_access(string $case): void
    {
        $attempt = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);
        $url = route('technical-assessments.report', $attempt->recruitment_application_id);
        $this->get($url)->assertRedirect(route('login'));
        $attributes = match ($case) {
            'candidate' => ['role' => Role::Candidate],
            'inactive' => ['role' => Role::Admin, 'active' => false],
            'password_change' => ['role' => Role::Admin, 'must_change_password' => true],
            default => ['role' => Role::Admin],
        };
        $actor = User::factory()->create($attributes);
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
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.report_opened']);
    }

    public function test_report_uses_legacy_section_indexes_and_questionnaire_answers(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        $package['condition_questions'] = [];
        $package['sections'][] = ['title' => 'Kuesioner', 'kind' => 'questionnaire', 'questions' => [['id' => 'legacy_condition', 'prompt' => 'Kondisi saat mengerjakan?']]];
        $attempt = TechnicalAssessmentAttempt::factory()->create([
            'form' => 'B', 'package' => $package, 'version' => 'versi-lama', 'completed_at' => now(),
            'answers' => [0 => ['process' => 'Penjelasan proses versi lama.'], 3 => ['legacy_condition' => 'Tidak ada gangguan pada versi lama.']],
        ]);

        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])
            ->get(route('technical-assessments.report', $attempt->recruitment_application_id))
            ->assertOk()->assertSee('Penjelasan proses versi lama.')->assertSee('Tidak ada gangguan pada versi lama.')
            ->assertSee('Ringkasan poin belum tersedia.')->assertSee('Belum dinilai');
    }
}
