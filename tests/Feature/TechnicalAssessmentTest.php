<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\TechnicalAssessments;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\RecruitmentApplication;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\User;
use App\Services\TechnicalAssessmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TechnicalAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $form = 'A', array $attributes = []): TechnicalAssessmentAttempt
    {
        $packages = app(TechnicalAssessmentService::class)->packages();
        $package = $packages[$form === 'A2' ? 'A' : $form];
        if ($form === 'A2') {
            $package = array_replace($package, ['title' => 'Form A2 · IPIP BFM 25 opsional', 'version' => '2026-10-15.1', 'sections' => [$package['sections'][3]], 'scoring' => [], 'condition_questions' => $packages['B']['condition_questions']]);
        }

        return TechnicalAssessmentAttempt::factory()->create($attributes + ['form' => $form, 'package' => $package, 'version' => $package['version']]);
    }

    private function simulationAnswers(): array
    {
        return [
            'priority_1_priority' => 'Pembayaran',
            'priority_1_action' => 'Tahan pembayaran dan verifikasi independen.',
            'priority_1_evidence' => 'Rekening master dan konfirmasi independen.',
            'priority_1_coordination' => 'Finance dan Manager.',
            'priority_2_priority' => 'Laporan',
            'priority_2_action' => 'Klarifikasi angka sebelum rapat.',
            'priority_2_evidence' => 'Sumber data yang telah dikonfirmasi.',
            'priority_2_coordination' => 'Pemilik data dan pimpinan rapat.',
            'priority_3_priority' => 'Administrasi',
            'priority_3_action' => 'Selesaikan rekap sebelum tenggat besok.',
            'priority_3_evidence' => 'Daftar kehadiran pelatihan.',
            'priority_3_coordination' => 'PIC pelatihan.',
        ];
    }

    private function legacyFormBPackage(): array
    {
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        $simulation = $package['sections'][1];
        $initial = ['title' => 'Simulasi dinamis - keputusan awal', 'seconds' => 240, 'material' => $simulation['material'], 'questions' => []];
        foreach ([1, 2, 3] as $number) {
            $initial['questions'][] = ['id' => 'priority_'.$number, 'label' => 'Prioritas '.$number, 'prompt' => 'Tindakan, batas, kontrol, dan koordinasi.', 'type' => 'text'];
        }
        $facts = ['title' => 'Simulasi dinamis - fakta baru', 'seconds' => 240, 'material' => array_merge($simulation['material'], $simulation['followup_material']), 'questions' => [collect($simulation['questions'])->firstWhere('id', 'revised_decision')]];
        array_splice($package['sections'], 1, 1, [$initial, $facts]);
        $package['version'] = '2026-10-21.3';

        return $package;
    }

    private function loginFor(TechnicalAssessmentAttempt $attempt): void
    {
        $this->actingAs($attempt->application->user)->withSession(['portal_session_version' => $attempt->application->user->session_version]);
    }

    private function payload(TechnicalAssessmentAttempt $attempt, string $action, array $answers = []): array
    {
        $attempt->refresh();

        return ['action' => $action, 'section' => $attempt->section_index, 'revision' => $attempt->revision, 'answers' => $answers];
    }

    private function completeFormAFor(TechnicalAssessmentAttempt $attempt): void
    {
        $this->attempt('A', ['recruitment_application_id' => $attempt->recruitment_application_id, 'completed_at' => now()]);
    }

    public function test_admin_assigns_versioned_package_and_duplicate_assignment_preserves_existing_work(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create();
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->assertSee('Tes teknis online')->assertSee('Kunci dan panduan assessor')
            ->set('applicationIds', [$application->id])->set('opensAt', now()->subMinute()->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('deadline', now()->addHours(3)->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())->set('reviewed', true)
            ->call('assign')->assertHasNoErrors();
        $attempt = TechnicalAssessmentAttempt::sole();
        $this->assertSame([900, 900, 600, 420], array_column($attempt->package['sections'], 'seconds'));
        $this->assertSame('C', array_key_first($attempt->package['scoring']['A01']));
        $attempt->update(['answers' => [1 => ['A01' => 'B']]]);

        $page->set('applicationIds', [$application->id])->call('assign')->assertHasNoErrors();

        $this->assertDatabaseCount('technical_assessment_attempts', 1);
        $this->assertSame([1 => ['A01' => 'B']], $attempt->fresh()->answers);
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.assigned')->count());
    }

    public function test_admin_updates_package_and_question_titles_for_new_assignments_without_changing_existing_sessions(): void
    {
        $this->freezeTime();
        $existing = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15), 'answers' => [0 => ['A01' => 'C']]]);
        $original = $existing->fresh()->getAttributes();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create();

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->set('packageTitle', 'Tes Administrasi')
            ->set('sectionTitles.0', 'Penalaran Administrasi')
            ->set('questionTitles.A01.label', 'Pemahaman prosedur')
            ->set('questionTitles.A01.prompt', '<script>alert(1)</script> Pertanyaan administrasi')
            ->set('reviewed', true)->call('savePackage')->assertHasNoErrors()->assertSet('reviewed', false)
            ->assertSee('Nama paket dan soal berhasil diperbarui')
            ->set('applicationIds', [$application->id])
            ->set('opensAt', now()->subMinute()->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('deadline', now()->addHours(3)->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('reviewed', true)->call('assign')->assertHasNoErrors();

        $assigned = TechnicalAssessmentAttempt::where('recruitment_application_id', $application->id)->sole();
        $this->assertSame('Tes Administrasi', $assigned->package['title']);
        $this->assertSame('Penalaran Administrasi', $assigned->package['sections'][0]['title']);
        $this->assertSame('Pemahaman prosedur', $assigned->package['sections'][0]['questions'][0]['label']);
        $this->assertSame($existing->package['scoring'], $assigned->package['scoring']);
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseHas('app_settings', ['key' => 'technical_assessment_labels_A']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_assessment.package_updated', 'actor_id' => $admin->id]);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->assertSet('packageTitle', 'Tes Administrasi')->assertSet('questionTitles.A01.label', 'Pemahaman prosedur')
            ->set('form', 'B')->assertSet('questionTitles.B01.label', 'B01')->set('form', 'A')->assertSet('packageTitle', 'Tes Administrasi');
        $assigned->update(['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($assigned);
        $this->get(route('candidate.technical-assessments.show', $assigned))->assertSee('Pemahaman prosedur')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    #[TestWith(['packageTitle', '', 'title'])]
    #[TestWith(['sectionTitles.0', '', 'sections.0'])]
    #[TestWith(['questionTitles.A01.label', '', 'questions.A01.label'])]
    #[TestWith(['questionTitles.A01.prompt', '', 'questions.A01.prompt'])]
    #[TestWith(['questionTitles.A01.options', ['Z' => 'Jawaban tambahan'], 'questions.A01'])]
    #[TestWith(['questionTitles.EXTRA', ['label' => 'Soal tambahan', 'prompt' => 'Tidak diizinkan'], 'questions'])]
    public function test_package_edits_reject_invalid_titles_and_unexpected_question_data(string $property, mixed $value, string $error): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->set($property, $value)
            ->call('savePackage')->assertHasErrors($error);

        $this->assertDatabaseMissing('app_settings', ['key' => 'technical_assessment_labels_A']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.package_updated']);
    }

    public function test_revoked_admin_cannot_update_packages(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)->set('packageTitle', 'Perubahan terlarang');
        $admin->update(['active' => false]);

        $page->call('savePackage')->assertForbidden();

        $this->assertDatabaseMissing('app_settings', ['key' => 'technical_assessment_labels_A']);
    }

    public function test_form_a_includes_behavior_followup_and_removes_instruction_stage_and_separate_a2_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create();
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->assertViewHas('packages', fn (array $packages): bool => array_keys($packages) === ['A', 'B'])
            ->assertDontSee('Orientasi dan latihan');
        $this->actingAs($admin);
        try {
            app(TechnicalAssessmentService::class)->assign($admin, ['application_id' => $application->id, 'form' => 'A2', 'opens_at' => now()->subMinute(), 'deadline' => now()->addHour(), 'durations' => [420], 'reviewed' => true]);
            $this->fail('A2 must not be assignable separately.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
        $this->assertDatabaseCount('technical_assessment_attempts', 0);
    }

    public function test_new_form_b_starts_with_reasoning_without_identity_and_conditions(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create();
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->set('form', 'B')
            ->assertDontSee('Identitas dan kondisi')->assertSet('durations', [300, 480, 300])
            ->set('applicationIds', [$application->id])
            ->set('opensAt', now()->subMinute()->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('deadline', now()->addHours(2)->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('reviewed', true)->call('assign')->assertHasNoErrors();
        $attempt = TechnicalAssessmentAttempt::sole();
        $this->completeFormAFor($attempt);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);

        $this->postJson($url, ['action' => 'consent', 'consent' => true])->assertOk();
        $this->get(route('candidate.technical-assessments.show', $attempt))
            ->assertSee('Mulai Penalaran paralel')->assertDontSee('Identitas dan kondisi');
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('section', 0)->assertJsonPath('remaining', 300);
        $this->postJson($url, $this->payload($attempt, 'save', ['B01' => 'B']))->assertOk();

        $this->assertSame([0 => ['B01' => 'B']], $attempt->fresh()->answers);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_next_locks_reasoning_choices_and_reveals_process_with_the_same_stage_timer(int $section): void
    {
        $this->freezeTime();
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        if ($section === 1) {
            array_unshift($package['sections'], ['title' => 'Identitas dan kondisi', 'seconds' => 120, 'material' => [], 'questions' => []]);
        }
        $attempt = $this->attempt('B', ['package' => $package, 'consented_at' => now(), 'section_index' => $section,
            'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(5)]);
        $this->completeFormAFor($attempt);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $show = route('candidate.technical-assessments.show', $attempt);
        $choices = ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C'];
        $started = $attempt->section_started_at->toIso8601String();
        $expires = $attempt->section_expires_at->toIso8601String();

        $this->get($show)->assertSee('value="next"', false)->assertSee('name="answers[B04]"', false)
            ->assertDontSee('name="answers[process]"', false)
            ->assertDontSee('Jelaskan informasi yang digunakan, langkah berpikir, dan pemeriksaan jawaban untuk B04.');
        $staleSave = $this->payload($attempt, 'save', ['B01' => 'A']);
        $this->travel(30)->seconds();
        $this->postJson($url, $this->payload($attempt, 'next', $choices))
            ->assertJsonPath('section', $section)->assertJsonPath('active', true)
            ->assertJsonPath('reasoning_locked', true)->assertJsonPath('remaining', 270);

        $this->assertNotNull($attempt->fresh()->reasoning_answers_locked_at);
        $this->assertSame($started, $attempt->fresh()->section_started_at->toIso8601String());
        $this->assertSame($expires, $attempt->fresh()->section_expires_at->toIso8601String());
        $this->assertSame([$section => $choices], $attempt->fresh()->answers);
        $this->get($show)->assertSee('name="answers[process]"', false)
            ->assertDontSee('name="answers[B01]"', false)->assertDontSee('value="next"', false);
        $this->postJson($url, $staleSave)->assertConflict();
        $this->postJson($url, $this->payload($attempt, 'save', ['process' => 'Pisahkan faktor yang berubah.']))->assertOk();
        $this->get($show)->assertSee('Pisahkan faktor yang berubah.');
        $this->assertSame($choices + ['process' => 'Pisahkan faktor yang berubah.'], $attempt->fresh()->answers[$section]);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->id, 'action' => 'technical_assessment.reasoning_answers_locked']);
        $this->postJson($url, $this->payload($attempt, 'finish', ['process' => 'Periksa kembali bukti.']))
            ->assertJsonPath('section', $section + 1)->assertJsonPath('active', false);
        $this->assertSame($choices + ['process' => 'Periksa kembali bukti.'], $attempt->fresh()->answers[$section]);
        $attempt->update(['deadline' => now()]);
        $this->getJson($show)->assertJsonPath('completed', true);
        $this->assertSame(4, $attempt->fresh()->scores['Penalaran objektif']);
    }

    #[TestWith(['next', ['B01' => 'B', 'B02' => 'C', 'B03' => 'A']])]
    #[TestWith(['next', ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'Z']])]
    #[TestWith(['save', ['process' => 'Belum boleh terlihat.']])]
    #[TestWith(['finish', ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']])]
    public function test_reasoning_rejects_incomplete_next_invalid_choices_and_skipping_the_lock(string $action, array $answers): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(5)]);
        $this->loginFor($attempt);
        $original = $attempt->fresh()->getAttributes();

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, $action, $answers))->assertUnprocessable();

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['save', ['B01' => 'A']])]
    #[TestWith(['finish', ['B04' => 'D', 'process' => 'Mengubah pilihan terkunci.']])]
    #[TestWith(['next', ['B01' => 'A', 'B02' => 'A', 'B03' => 'A', 'B04' => 'A']])]
    public function test_locked_reasoning_choices_cannot_be_overwritten_or_relocked(string $action, array $answers): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(5)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'next', ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']))->assertOk();
        $original = $attempt->fresh()->getAttributes();

        $response = $this->postJson($url, $this->payload($attempt, $action, $answers));

        if ($action === 'next') {
            $response->assertConflict();
        } else {
            $response->assertUnprocessable();
        }
        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.reasoning_answers_locked')->count());
    }

    #[TestWith(['next', false])]
    #[TestWith(['save', true])]
    public function test_reasoning_timeout_discards_late_next_or_process_answers(string $action, bool $locked): void
    {
        $this->freezeTime();
        $choices = ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C'];
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_started_at' => now()->subMinutes(5), 'section_expires_at' => now(),
            'reasoning_answers_locked_at' => $locked ? now()->subMinute() : null, 'answers' => [0 => $choices]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, $action, $locked ? ['process' => 'Terlambat.'] : $choices))
            ->assertJsonPath('section', 1)->assertJsonPath('active', false);

        $this->assertSame([0 => $choices], $attempt->fresh()->answers);
        $this->assertSame($locked, $attempt->fresh()->reasoning_answers_locked_at !== null);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.reasoning_answers_locked']);
    }

    public function test_next_is_rejected_outside_parallel_reasoning_without_changing_answers(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'next', ['A01' => 'C']))->assertUnprocessable();

        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertNull($attempt->fresh()->reasoning_answers_locked_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_form_b_preserves_custom_titles_saved_before_identity_stage_removal(): void
    {
        AppSetting::factory()->create(['key' => 'technical_assessment_labels_B', 'value' => [
            'title' => 'Tes lanjutan administrasi',
            'sections' => ['Identitas lama', 'Penalaran khusus', 'Keputusan khusus', 'Fakta khusus', 'Probe khusus', 'Kuesioner khusus'],
            'questions' => ['identity_conditions' => ['label' => 'Identitas lama', 'prompt' => 'Konfirmasi lama'], 'priority_1' => ['label' => 'Prioritas 1', 'prompt' => 'Keputusan awal.']],
        ]]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->set('form', 'B')
            ->assertSet('packageTitle', 'Tes lanjutan administrasi')
            ->assertSet('sectionTitles', ['Penalaran khusus', 'Keputusan khusus', 'Probe khusus'])
            ->call('savePackage')->assertHasNoErrors()
            ->set('form', 'A')->set('form', 'B')
            ->assertSet('sectionTitles', ['Penalaran khusus', 'Keputusan khusus', 'Probe khusus'])
            ->assertDontSee('Identitas lama');
    }

    public function test_old_five_stage_labels_cannot_swap_probe_and_condition_notes(): void
    {
        AppSetting::factory()->create(['key' => 'technical_assessment_labels_B', 'value' => [
            'title' => 'Sesi B',
            'sections' => ['Identitas dan kondisi', 'Penalaran paralel dan penjelasan proses', 'Simulasi dinamis · keputusan awal', 'Simulasi dinamis · fakta baru', 'Probe konsistensi'],
            'questions' => [
                'identity_conditions' => ['label' => 'Identitas', 'prompt' => 'Konfirmasi lama.'],
                'priority_1' => ['label' => 'Prioritas 1', 'prompt' => 'Keputusan awal.'],
                'probe_1' => ['label' => 'Pertanyaan 1', 'prompt' => 'Bagaimana Anda biasanya membedakan fakta, asumsi, dan informasi yang belum diketahui?'],
            ],
        ]]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->set('form', 'B')
            ->assertSet('sectionTitles', ['Penalaran paralel', 'Simulasi dinamis', 'Probe konsistensi'])
            ->assertSet('durations', [300, 480, 300])
            ->assertSet('questionTitles.probe_1.prompt', 'Bagaimana Anda biasanya membedakan fakta, asumsi, dan informasi yang belum diketahui?')
            ->call('savePackage')->assertHasNoErrors()->set('form', 'A')->set('form', 'B')
            ->assertSet('sectionTitles', ['Penalaran paralel', 'Simulasi dinamis', 'Probe konsistensi']);

        $this->assertSame(['B01', 'priority_1_priority', 'probe_1'], AppSetting::where('key', 'technical_assessment_labels_B')->sole()->value['section_question_ids']);
    }

    public function test_legacy_merged_attempt_displays_correct_three_sections_and_preserves_saved_condition_notes(): void
    {
        $this->freezeTime();
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        $package['sections'][0]['title'] = 'Penalaran paralel dan penjelasan proses';
        $package['sections'][1]['title'] = 'Simulasi dinamis · keputusan awal';
        $package['sections'][2]['title'] = 'Simulasi dinamis · fakta baru';
        $package['sections'][] = ['title' => 'Probe konsistensi', 'kind' => 'questionnaire', 'seconds' => 300, 'material' => [], 'questions' => $package['condition_questions']];
        $package['condition_questions'] = [];
        $attempt = $this->attempt('B', ['version' => '2026-10-21.4', 'package' => $package, 'section_index' => 4, 'completed_at' => now(),
            'answers' => [2 => ['probe_1' => 'Pisahkan bukti dari asumsi.'], 3 => ['technical_conditions' => 'Koneksi sempat terputus.']]]);
        $original = $attempt->fresh()->getAttributes();
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments'))->assertSee('18 menit - 3 tahap')->assertDontSee('Versi')->assertDontSee($attempt->version);
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Catatan kondisi dan konfirmasi')->assertSee('Koneksi sempat terputus.')
            ->assertSee('18 menit - 3 tahap')->assertDontSee('Versi')->assertDontSee($attempt->version);
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)
            ->assertSeeInOrder(['Penalaran paralel', 'Simulasi dinamis', 'Probe konsistensi', 'Pisahkan bukti dari asumsi.', 'Catatan kondisi', 'Koneksi sempat terputus.']);

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertSame(['Penalaran paralel', 'Simulasi dinamis', 'Probe konsistensi'], array_column($attempt->sectionsForDisplay(), 'title'));
    }

    #[TestWith([2, 'Probe konsistensi'])]
    #[TestWith([3, 'Catatan kondisi dan konfirmasi'])]
    public function test_legacy_active_probe_and_notes_show_their_own_titles_without_a_fourth_test_stage(int $index, string $title): void
    {
        $this->freezeTime();
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        $package['sections'][2]['title'] = 'Simulasi dinamis · fakta baru';
        $package['sections'][] = ['title' => 'Probe konsistensi', 'kind' => 'questionnaire', 'seconds' => 300, 'material' => [], 'questions' => $package['condition_questions']];
        $package['condition_questions'] = [];
        $attempt = $this->attempt('B', ['package' => $package, 'consented_at' => now(), 'section_index' => $index, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(5)]);
        $original = $attempt->fresh()->getAttributes();
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments.show', $attempt))
            ->assertSee('<h2>'.$title.'</h2>', false)->assertDontSee('Tahap 4 / 3');
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->assertDontSee('Tahap 4')->assertDontSee('Tahap 4 dari 3');

        $this->assertSame($original, $attempt->fresh()->getAttributes());
    }

    public function test_legacy_form_b_keeps_locked_initial_answers_visible_at_the_original_stage_index(): void
    {
        $this->freezeTime();
        $package = $this->legacyFormBPackage();
        array_unshift($package['sections'], ['title' => 'Identitas dan kondisi', 'seconds' => 120, 'material' => [], 'questions' => []]);
        $package['version'] = '2026-10-21.2';
        $attempt = $this->attempt('B', ['package' => $package, 'version' => $package['version'], 'consented_at' => now(), 'section_index' => 3,
            'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(4), 'answers' => [2 => ['priority_1' => 'Keputusan awal lama tetap terkunci.']]]);
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments.show', $attempt))
            ->assertSee('Keputusan awal Anda yang sudah dikunci')->assertSee('Keputusan awal lama tetap terkunci.');

        $this->assertSame($package, $attempt->fresh()->package);
        $this->assertSame([2 => ['priority_1' => 'Keputusan awal lama tetap terkunci.']], $attempt->fresh()->answers);
    }

    #[TestWith(['A', 0, false])]
    #[TestWith(['A', 3, true])]
    #[TestWith(['B', 0, false])]
    public function test_two_minute_reminder_is_enabled_only_for_form_a_behavior_stage(string $form, int $section, bool $enabled): void
    {
        $this->freezeTime();
        $attempt = $this->attempt($form, ['consented_at' => now(), 'section_index' => $section, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(7)]);
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments.show', $attempt))
            ->assertSee('data-two-minute-warning="'.($enabled ? 'true' : 'false').'"', false);
    }

    public function test_numerical_table_precedes_a05_and_documents_render_in_comparison_columns(): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.show', $attempt);
        $this->get($url)->assertSeeInOrder(['<legend>A04</legend>', 'Tabel kinerja tiga minggu', '<legend>A05</legend>'], false);

        $attempt->update(['section_index' => 1]);
        $this->get($url)->assertSee('class="assessment-documents"', false)->assertSee('Dokumen A Permintaan Pembayaran')->assertSee('Dokumen B Register Kontrol')
            ->assertSeeInOrder([
                '<h3>Aturan Kontrol</h3>',
                '<ul class="assessment-control-rules">',
                '<li>Pembayaran di atas Rp400 juta memerlukan persetujuan Manager dan Finance.</li>',
                '<li>Perubahan rekening harus diverifikasi melalui saluran independen sebelum pelepasan.</li>',
                '<li>Nomor invoice dan jumlah harus konsisten dengan register.</li>',
                '<li>Dokumen pajak harus masih berlaku pada tanggal penelaahan.</li>',
                '</ul>',
            ], false);
        $attempt->update(['completed_at' => now(), 'section_started_at' => null, 'section_expires_at' => null]);
        $this->get($url)->assertDontSee('Apakah akomodasi yang disepakati telah tersedia');
        $this->postJson(route('candidate.technical-assessments.update', $attempt), ['action' => 'conditions', 'conditions' => ['accommodations' => 'Tidak ditanyakan'], 'confirmation' => true])->assertJsonValidationErrors('conditions');
        $this->assertNull($attempt->fresh()->conditions);
    }

    public function test_assignment_requires_review_and_admin_and_rejects_inactive_applications(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create(['archived_at' => now(), 'active_user_id' => null]);
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->set('applicationIds', [$application->id])->set('opensAt', now()->subDay()->toDateTimeString())
            ->set('deadline', now()->addDay()->timezone(AppSetting::valueFor('timezone'))->toDateTimeString());
        $page->call('assign')->assertHasErrors('reviewed');
        $page->set('reviewed', true)->call('assign')->assertHasErrors('application_id');
        Livewire::actingAs($application->user)->test(TechnicalAssessments::class)->assertForbidden();
        $this->assertDatabaseCount('technical_assessment_attempts', 0);
    }

    public function test_pasted_candidate_emails_preserve_selection_and_reject_ineligible_candidates(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $first = RecruitmentApplication::factory()->create();
        $second = RecruitmentApplication::factory()->create();
        $archived = RecruitmentApplication::factory()->create(['archived_at' => now(), 'active_user_id' => null]);
        $purged = RecruitmentApplication::factory()->create(['purged_at' => now()]);
        $inactive = RecruitmentApplication::factory()->create();
        $inactive->user->update(['active' => false]);
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->set('applicationIds', [(string) $first->id])->set('search', 'Pencarian sebelumnya')
            ->call('selectApplicationsByEmail', strtoupper($second->user->email)."\t\r\n".$first->user->email.','.$second->user->email)
            ->assertHasNoErrors()->assertSet('applicationIds', [(string) $first->id, (string) $second->id])
            ->assertSet('search', '')->assertSee('2 lamaran kandidat dipilih untuk tes teknis.');

        foreach ([$archived->user->email, $purged->user->email, $inactive->user->email, 'missing@example.test', 'invalid'] as $email) {
            $page->call('selectApplicationsByEmail', $email)->assertHasErrors('search')
                ->assertSet('applicationIds', [(string) $first->id, (string) $second->id]);
        }

        $this->assertDatabaseCount('technical_assessment_attempts', 0);
        $admin->update(['active' => false]);
        $page->call('selectApplicationsByEmail', $first->user->email)->assertForbidden();
    }

    public function test_candidate_picker_search_is_independent_of_result_search_and_keeps_selected_ids(): void
    {
        $first = $this->attempt();
        $second = $this->attempt();
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->set('applicationIds', [(string) $first->recruitment_application_id])
            ->set('search', $second->application->user->email)
            ->assertViewHas('applications', fn ($applications) => $applications->modelKeys() === [$second->recruitment_application_id])
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 2)
            ->assertSet('applicationIds', [(string) $first->recruitment_application_id])
            ->set('paginators.page', 2)->set('resultSearch', $first->application->user->email)
            ->assertSet('paginators.page', 1)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->getCollection()->modelKeys() === [$first->id])
            ->assertViewHas('applications', fn ($applications) => $applications->modelKeys() === [$second->recruitment_application_id]);
    }

    public function test_admin_can_delete_only_the_selected_technical_result_after_confirmation(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = $this->attempt('A', ['completed_at' => now(), 'answers' => [1 => ['A01' => 'C']], 'scores' => ['Penalaran' => 1], 'review' => ['status' => 'Kemampuan terdemonstrasi', 'notes' => 'Bukti penilaian kandidat.']]);
        $application = $attempt->application;
        $otherForm = $this->attempt('B', ['recruitment_application_id' => $application->id]);
        $otherCandidate = $this->attempt();
        $activity = AuditLog::record('technical_assessment.answers_saved', $attempt, $application->user);

        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->assertSee('Hapus hasil tes')
            ->call('confirmAttemptDeletion', $attempt->id)->assertSee('Ya, hapus hasil tes')->assertSee('dihapus permanen');
        $this->assertModelExists($attempt);
        $page->call('cancelAttemptDeletion')->assertSet('deletingAttemptId', null);
        $this->assertModelExists($attempt);
        $page->call('confirmAttemptDeletion', $attempt->id)->call('deleteAttempt')
            ->assertHasNoErrors()->assertSet('deletingAttemptId', null)->assertSet('attemptId', null)
            ->assertSet('ratings', [])->assertSet('reviewStatus', '')->assertSet('reviewNotes', '')
            ->assertViewHas('selected', null)->assertSee('Hasil tes teknis berhasil dihapus');

        $this->assertModelMissing($attempt);
        $this->assertModelExists($application);
        $this->assertModelExists($application->user);
        $this->assertModelExists($otherForm);
        $this->assertModelExists($otherCandidate);
        $this->assertModelExists($activity);
        $audit = AuditLog::where('action', 'technical_assessment.attempt_deleted')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($attempt->id, $audit->target_id);
        $this->assertSame(['recruitment_application_id' => $application->id, 'form' => 'A', 'version' => $attempt->version], $audit->metadata);
        $this->loginFor($attempt);
        $this->getJson(route('candidate.technical-assessments.show', $attempt))->assertNotFound();
        $this->postJson(route('candidate.technical-assessments.update', $attempt), ['action' => 'save', 'section' => 1, 'revision' => 0, 'answers' => ['A01' => 'C']])->assertNotFound();
    }

    public function test_technical_result_deletion_requires_confirmation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = $this->attempt();

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->call('deleteAttempt')->assertStatus(422);

        $this->assertModelExists($attempt);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.attempt_deleted']);
    }

    public function test_switching_technical_sessions_cancels_pending_deletion(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $first = $this->attempt();
        $second = $this->attempt();

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->call('selectAttempt', $first->id)->call('confirmAttemptDeletion', $first->id)
            ->call('selectAttempt', $second->id)->assertSet('deletingAttemptId', null)
            ->call('deleteAttempt')->assertStatus(422);

        $this->assertModelExists($first);
        $this->assertModelExists($second);
    }

    public function test_admin_can_delete_an_active_technical_session_and_assign_a_fresh_attempt(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15), 'answers' => [1 => ['A01' => 'C']]]);

        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->call('confirmAttemptDeletion', $attempt->id)
            ->assertSee('Sesi yang sedang berlangsung akan kehilangan akses.')
            ->call('deleteAttempt')->assertHasNoErrors();
        $this->assertModelMissing($attempt);
        $page->set('applicationIds', [$attempt->recruitment_application_id])
            ->set('opensAt', now()->subMinute()->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('deadline', now()->addHours(3)->timezone(AppSetting::valueFor('timezone'))->toDateTimeString())
            ->set('reviewed', true)->call('assign')->assertHasNoErrors();

        $fresh = TechnicalAssessmentAttempt::sole();
        $this->assertNotSame($attempt->id, $fresh->id);
        $this->assertSame($attempt->recruitment_application_id, $fresh->recruitment_application_id);
        $this->assertSame([], $fresh->answers);
        $this->assertNull($fresh->scores);
        $this->assertNull($fresh->review);
        $this->assertNull($fresh->section_started_at);
    }

    #[TestWith(['candidate', true, false])]
    #[TestWith(['admin', false, false])]
    #[TestWith(['admin', true, true])]
    public function test_technical_result_deletion_is_forbidden_without_active_admin_access(string $role, bool $active, bool $mustChangePassword): void
    {
        $attempt = $this->attempt();
        $actor = User::factory()->create(['role' => Role::from($role), 'active' => $active, 'must_change_password' => $mustChangePassword]);

        try {
            app(TechnicalAssessmentService::class)->deleteAttempt($actor, $attempt->id);
            $this->fail('Technical result deletion must require active admin access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertModelExists($attempt);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.attempt_deleted']);
    }

    public function test_admin_access_revoked_after_confirmation_cannot_delete_a_technical_result(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = $this->attempt();
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->call('confirmAttemptDeletion', $attempt->id);
        $admin->update(['active' => false]);

        $page->call('deleteAttempt')->assertForbidden();

        $this->assertModelExists($attempt);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.attempt_deleted']);
    }

    public function test_purged_application_results_cannot_be_deleted_through_technical_assessments(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = $this->attempt();
        $attempt->application->update(['purged_at' => now()]);

        try {
            app(TechnicalAssessmentService::class)->deleteAttempt($admin, $attempt->id);
            $this->fail('Purged application results must be unavailable.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertModelExists($attempt);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.attempt_deleted']);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('confirmAttemptDeletion', $attempt->id);
    }

    public function test_candidate_only_sees_own_current_stage_and_no_secret_data_in_html_or_json(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 0, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $url = route('candidate.technical-assessments.show', $attempt);
        $this->getJson($url)->assertUnauthorized();
        $this->loginFor($this->attempt());
        $this->get($url)->assertNotFound();
        $this->loginFor($attempt);
        $this->get($url)->assertSee('A01')->assertSee('Tabel', false)->assertDontSee('D01')->assertDontSee('S01')->assertDontSee('Kunci dan Skoring')->assertDontSee('IPP =');
        $this->getJson($url)->assertExactJson(['section' => 0, 'revision' => 0, 'active' => true, 'completed' => false, 'reasoning_locked' => false, 'simulation_locked' => false, 'remaining' => 900]);
        $this->assertArrayNotHasKey('package', $attempt->toArray());
        $attempt->application->update(['archived_at' => now(), 'active_user_id' => null]);
        $this->get($url)->assertNotFound();
    }

    #[TestWith(['A'])]
    #[TestWith(['B'])]
    #[TestWith(['A2'])]
    public function test_start_requires_consent_and_timer_does_not_reset_after_refresh_or_duplicate_start(string $form): void
    {
        $this->freezeTime();
        $attempt = $this->attempt($form);
        if ($form !== 'A') {
            $this->completeFormAFor($attempt);
        }
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertUnprocessable();
        $this->postJson($url, ['action' => 'consent', 'consent' => false])->assertJsonValidationErrors('consent');
        $this->postJson($url, ['action' => 'consent', 'consent' => true])->assertOk();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('data-assessment-start', false)->assertDontSee('Menunggu assessor membuka');
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('active', true);
        $expires = $attempt->fresh()->section_expires_at->toIso8601String();
        $this->travel(30)->seconds();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee($attempt->package['sections'][0]['title']);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertOk();
        $this->assertSame($expires, $attempt->fresh()->section_expires_at->toIso8601String());
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.section_started')->count());
    }

    public function test_autosave_persists_answers_and_rejects_stale_revision_without_overwriting(): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 0, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $payload = $this->payload($attempt, 'save', ['A01' => 'C', 'A02' => 'B']);
        $this->postJson($url, $payload)->assertJsonPath('revision', 1);
        $this->postJson($url, $payload + [])->assertConflict();
        $this->assertSame([0 => ['A01' => 'C', 'A02' => 'B']], $attempt->fresh()->answers);
        $this->postJson($url, $this->payload($attempt, 'save', ['A01' => 'C', 'A02' => 'B']))->assertJsonPath('revision', 1);
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.answers_saved')->count());
    }

    #[TestWith(['A01', 'Z'])]
    #[TestWith(['D01', 'A'])]
    #[TestWith(['A01', ['C']])]
    public function test_invalid_answers_are_rejected_without_writing(string $id, mixed $answer): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 0, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'save', [$id => $answer]))->assertUnprocessable();

        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertSame(0, $attempt->fresh()->revision);
    }

    public function test_timeout_commits_stage_transition_and_discards_late_answer(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 0, 'section_started_at' => now()->subMinutes(15), 'section_expires_at' => now(), 'answers' => [0 => ['A01' => 'C']]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'save', ['A01' => 'B']))->assertJsonPath('section', 1)->assertJsonPath('active', false);

        $this->assertSame([0 => ['A01' => 'C']], $attempt->fresh()->answers);
        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_assessment.section_closed', 'reason' => 'timeout']);
    }

    public function test_completion_scores_manual_keys_and_sjt_weights_and_does_not_expose_result(): void
    {
        $answers = ['A01' => 'C', 'A02' => 'B', 'A03' => 'D', 'A04' => 'B', 'A05' => 'C', 'A06' => 'C', 'A07' => 'B', 'A08' => 'C', 'A09' => 'B', 'A10' => 'C', 'A11' => 'B', 'A12' => 'C'];
        $documents = ['D01' => 'A', 'D02' => 'B', 'D03' => 'C', 'D04' => 'C', 'D05' => 'B', 'D06' => 'C', 'D07' => 'D'];
        $sjt = ['S01' => 'B', 'S02' => 'B', 'S03' => 'C', 'S04' => 'B', 'S05' => 'B'];
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 2, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(10), 'answers' => [0 => $answers, 1 => $documents]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'finish', $sjt))->assertJsonPath('completed', false)->assertJsonPath('section', 3);
        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'start'))->assertJsonPath('active', true);
        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'finish', ['IPIP01' => '5', 'IPIP06' => '5', 'IPIP11' => '1', 'IPIP16' => '5', 'IPIP21' => '1']))->assertJsonPath('completed', true)->assertJsonMissingPath('scores');

        $scores = $attempt->fresh()->scores;
        $this->assertSame(12, $scores['Penalaran']);
        $this->assertSame(4, $scores['Verbal']);
        $this->assertSame(4, $scores['Numerik']);
        $this->assertSame(4, $scores['Logis']);
        $this->assertSame(7, $scores['Dokumen']);
        $this->assertSame(7, $scores['SJT']);
        $this->assertSame(87, $scores['IPP pilot']);
        $this->assertSame([], $scores['Perlu verifikasi']);
        $this->assertSame(5, $scores['E']);
        $this->assertNull($scores['ES']);
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Jawaban tes telah dikunci')->assertDontSee('IPP pilot');
    }

    public function test_deadline_finalizes_unanswered_and_critical_items_once_without_an_automatic_verdict(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['deadline' => now()]);
        $this->artisan('technical-assessments:finalize')->assertSuccessful();
        $this->artisan('technical-assessments:finalize')->assertSuccessful();
        $scores = $attempt->fresh()->scores;
        $this->assertSame(0, $scores['Penalaran']);
        $this->assertSame(['D03', 'D04', 'D07'], $scores['Perlu verifikasi']);
        $this->assertNull($attempt->fresh()->review);
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.completed')->count());
    }

    public function test_form_b_candidate_starts_stages_and_new_facts_stay_hidden_until_initial_decision_is_locked(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1]);
        $this->completeFormAFor($attempt);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $show = route('candidate.technical-assessments.show', $attempt);
        $answers = $this->simulationAnswers();
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('remaining', 480)->assertJsonPath('active', true);
        $started = $attempt->fresh()->section_started_at->toIso8601String();
        $expires = $attempt->fresh()->section_expires_at->toIso8601String();
        $this->get($show)->assertSee('Prioritas 1')->assertSee('Prioritas 2')->assertSee('Prioritas 3')
            ->assertSee('Tindakan dan batas')->assertSee('Bukti atau kontrol')->assertSee('PIC dan Koordinasi')
            ->assertSee('name="answers[priority_1_priority]"', false)->assertSee('name="answers[priority_3_coordination]"', false)
            ->assertDontSee('berpotensi disusupi')->assertDontSee('name="answers[revised_decision]"', false);
        $stale = $this->payload($attempt, 'save', $answers);
        $this->postJson($url, $this->payload($attempt, 'save', $answers))->assertOk();
        $this->travel(30)->seconds();

        $this->postJson($url, $this->payload($attempt, 'next', $answers))->assertJsonPath('section', 1)
            ->assertJsonPath('simulation_locked', true)->assertJsonPath('remaining', 450)->assertJsonPath('active', true);

        $this->assertNotNull($attempt->fresh()->simulation_answers_locked_at);
        $this->assertSame($started, $attempt->fresh()->section_started_at->toIso8601String());
        $this->assertSame($expires, $attempt->fresh()->section_expires_at->toIso8601String());
        $this->get($show)->assertSeeInOrder(['Tahan pembayaran dan verifikasi independen.', '<h2>Fakta baru</h2>', 'berpotensi disusupi', 'name="answers[revised_decision]"'], false)
            ->assertDontSee('name="answers[priority_1_priority]"', false)->assertSee('readonly', false);
        $this->postJson($url, $stale)->assertConflict();
        $this->postJson($url, $this->payload($attempt, 'save', ['revised_decision' => 'Simpan bukti dan eskalasi.']))->assertOk();
        $this->get($show)->assertSee('Simpan bukti dan eskalasi.');
        $this->assertSame($answers + ['revised_decision' => 'Simpan bukti dan eskalasi.'], $attempt->fresh()->answers[1]);
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->assertSee('Prioritas 1 - Bukti atau kontrol')->assertSee('Rekening master dan konfirmasi independen.');
        $this->loginFor($attempt);
        $this->postJson($url, $this->payload($attempt, 'finish', ['revised_decision' => 'Simpan bukti dan eskalasi.']))
            ->assertJsonPath('section', 2)->assertJsonPath('active', false);
        $this->assertSame($answers + ['revised_decision' => 'Simpan bukti dan eskalasi.'], $attempt->fresh()->answers[1]);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->id, 'action' => 'technical_assessment.simulation_answers_locked']);
    }

    #[TestWith(['priority_1_priority', null])]
    #[TestWith(['priority_3_coordination', ''])]
    public function test_simulation_requires_every_priority_field_before_revealing_facts(string $field, ?string $value): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(8)]);
        $this->loginFor($attempt);
        $answers = array_replace($this->simulationAnswers(), [$field => $value]);
        $original = $attempt->fresh()->getAttributes();

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'next', $answers))
            ->assertJsonValidationErrors('answers.'.$field);

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertDontSee('berpotensi disusupi');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['save', ['revised_decision' => 'Jawaban terlalu awal.']])]
    #[TestWith(['finish', []])]
    public function test_simulation_rejects_answering_facts_or_finishing_before_the_initial_lock(string $action, array $answers): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(8)]);
        $this->loginFor($attempt);
        $original = $attempt->fresh()->getAttributes();

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, $action, $answers))->assertUnprocessable();

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['save', ['priority_1_action' => 'Ubah setelah melihat fakta baru.']])]
    #[TestWith(['finish', ['priority_3_coordination' => 'Ganti PIC.', 'revised_decision' => 'Jawaban akhir.']])]
    public function test_simulation_rejects_changes_to_locked_priorities(string $action, array $answers): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(8)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'next', $this->simulationAnswers()))->assertOk();
        $original = $attempt->fresh()->getAttributes();

        $this->postJson($url, $this->payload($attempt, $action, $answers))->assertUnprocessable();

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.simulation_answers_locked')->count());
    }

    public function test_simulation_duplicate_next_does_not_reset_timer_or_overwrite_priorities(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(8)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'next', $this->simulationAnswers()))->assertOk();
        $original = $attempt->fresh()->getAttributes();
        $this->travel(30)->seconds();

        $this->postJson($url, $this->payload($attempt, 'next', $this->simulationAnswers()))->assertConflict();

        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.simulation_answers_locked')->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_simulation_timeout_preserves_saved_answers_and_rejects_late_changes(bool $locked): void
    {
        $this->freezeTime();
        $answers = $this->simulationAnswers();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now()->subMinutes(8), 'section_expires_at' => now(),
            'simulation_answers_locked_at' => $locked ? now()->subMinute() : null, 'answers' => [1 => $answers]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, $locked ? 'save' : 'next', $locked ? ['revised_decision' => 'Terlambat.'] : $answers))
            ->assertJsonPath('section', 2)->assertJsonPath('active', false);

        $this->assertSame([1 => $answers], $attempt->fresh()->answers);
        $this->assertSame($locked, $attempt->fresh()->simulation_answers_locked_at !== null);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.simulation_answers_locked']);
    }

    public function test_form_b_candidates_progress_independently_without_assessor_actions(): void
    {
        $this->freezeTime();
        $first = $this->attempt('B', ['consented_at' => now()]);
        $second = $this->attempt('B', ['consented_at' => now()]);
        foreach ([$first, $second] as $attempt) {
            $this->completeFormAFor($attempt);
            $this->loginFor($attempt);
            $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'start'))->assertJsonPath('active', true);
        }
        $secondExpires = $second->fresh()->section_expires_at->toIso8601String();
        $this->loginFor($first);
        $url = route('candidate.technical-assessments.update', $first);
        $this->postJson($url, $this->payload($first, 'next', ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']))->assertJsonPath('reasoning_locked', true);
        for ($section = 0; $section < 3; $section++) {
            $this->postJson($url, $this->payload($first, 'start'))->assertJsonPath('section', $section)->assertJsonPath('active', true);
            if ($section === 1) {
                $this->postJson($url, $this->payload($first, 'next', $this->simulationAnswers()))->assertJsonPath('simulation_locked', true);
            }
            $this->postJson($url, $this->payload($first, 'finish'))->assertJsonPath('section', $section + 1)->assertJsonPath('active', false);
        }

        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertSame(0, $second->fresh()->section_index);
        $this->assertNull($second->fresh()->completed_at);
        $this->assertSame($secondExpires, $second->fresh()->section_expires_at->toIso8601String());
        $this->postJson(route('candidate.technical-assessments.update', $second), $this->payload($second, 'finish'))->assertNotFound();
    }

    public function test_form_b_objective_score_and_manual_review_keep_dimensions_separate(): void
    {
        $attempt = $this->attempt('B', ['deadline' => now(), 'answers' => [0 => ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']]]);
        $this->artisan('technical-assessments:finalize')->assertSuccessful();
        $this->assertSame(4, $attempt->fresh()->scores['Penalaran objektif']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $page = Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)
            ->set('reviewStatus', 'Kemampuan terdemonstrasi')->set('reviewNotes', 'Penjelasan dan kontrol didukung bukti langsung.');
        $page->call('saveReview')->assertHasErrors('ratings');
        $page->set('ratings', array_fill_keys(TechnicalAssessmentService::RATING_DIMENSIONS, 3))->assertSet('ratings', array_fill_keys(TechnicalAssessmentService::RATING_DIMENSIONS, 3))->call('saveReview')->assertHasNoErrors();
        $this->assertSame($admin->id, $attempt->fresh()->review['assessor_id']);
        $this->assertSame(3, $attempt->fresh()->review['ratings']['Adaptasi']);
    }

    public function test_ipip_uses_reverse_scoring_and_does_not_compute_incomplete_factors_or_ipp(): void
    {
        $attempt = $this->attempt('A2', ['deadline' => now(), 'answers' => [0 => ['IPIP01' => '5', 'IPIP06' => '5', 'IPIP11' => '1', 'IPIP16' => '5', 'IPIP21' => '1', 'IPIP04' => '1']]]);

        $this->artisan('technical-assessments:finalize')->assertSuccessful();

        $scores = $attempt->fresh()->scores;
        $this->assertSame(5, $scores['E']);
        $this->assertNull($scores['ES']);
        $this->assertNull($scores['A']);
        $this->assertArrayNotHasKey('IPP pilot', $scores);
    }

    public function test_probe_finishes_three_part_form_b_before_condition_confirmation(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 2, 'answers' => [0 => ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']]]);
        $this->completeFormAFor($attempt);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $show = route('candidate.technical-assessments.show', $attempt);
        $probe = ['probe_1' => 'Pisahkan bukti dan asumsi.', 'probe_2' => 'Kalkulator sederhana.', 'probe_3' => 'Tidak ada.', 'probe_4' => 'Periksa ulang bukti.'];
        $conditions = ['technical_conditions' => '<script>alert(1)</script>', 'unfinished' => 'Tidak ada.', 'other_conditions' => 'Kondisi stabil.'];

        $this->get($show)->assertSee('Probe konsistensi')->assertSee('Tahap 3 / 3')->assertSee('Alokasi waktu 5 menit.');
        $this->postJson($url, ['action' => 'conditions', 'conditions' => $conditions, 'confirmation' => true])->assertUnprocessable();
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('remaining', 300);
        $this->get($show)->assertSee('Bagaimana Anda biasanya membedakan fakta, asumsi, dan informasi yang belum diketahui?')
            ->assertSee('Pada Form A, alat atau bantuan apa saja yang Anda gunakan?')
            ->assertSee('Apakah ada kondisi yang dapat menjelaskan hasil yang berbeda antara Form A dan Form B?')
            ->assertSee('Bila jawaban Anda pada Form A ternyata keliru, bagaimana Anda akan memperbaiki prosesnya?')
            ->assertDontSee('Apakah ada gangguan teknis?');
        $this->postJson($url, $this->payload($attempt, 'save', $conditions))->assertUnprocessable();
        $this->postJson($url, $this->payload($attempt, 'finish', $probe))->assertJsonPath('completed', true);

        $this->assertSame($probe, $attempt->fresh()->answers[2]);
        $this->assertSame(4, $attempt->fresh()->scores['Penalaran objektif']);
        $this->get($show)->assertSee('Catatan kondisi dan konfirmasi')->assertSee('name="conditions[technical_conditions]"', false)
            ->assertSee('Apakah ada gangguan teknis? Tuliskan waktu, bagian, dan dampaknya!')
            ->assertDontSee('Mulai Kuesioner')->assertDontSee('Tahap 4');
        $this->postJson($url, ['action' => 'conditions', 'conditions' => $conditions, 'confirmation' => false])->assertJsonValidationErrors('confirmation');
        $this->assertNull($attempt->fresh()->conditions);
        $this->postJson($url, ['action' => 'conditions', 'conditions' => $conditions, 'confirmation' => true])->assertOk();
        $this->assertSame($conditions, $attempt->fresh()->conditions);
        $this->get($show)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)
            ->call('selectAttempt', $attempt->id)->assertSee('Probe konsistensi')->assertSee('Kondisi stabil.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_questionnaire_timeout_preserves_saved_notes_and_rejects_late_changes(): void
    {
        $this->freezeTime();
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        $package['sections'][] = ['title' => 'Kuesioner', 'kind' => 'questionnaire', 'seconds' => 300, 'material' => [], 'questions' => $package['condition_questions']];
        $package['condition_questions'] = [];
        $attempt = $this->attempt('B', ['package' => $package, 'consented_at' => now(), 'section_index' => 3, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(5), 'answers' => [3 => ['technical_conditions' => 'Koneksi sempat terputus.']]]);
        $this->completeFormAFor($attempt);
        $this->loginFor($attempt);
        $this->travel(5)->minutes();

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'save', ['technical_conditions' => 'Jawaban terlambat.']))->assertJsonPath('completed', true);

        $this->assertSame('Koneksi sempat terputus.', $attempt->fresh()->answers[3]['technical_conditions']);
        $this->assertNotNull($attempt->fresh()->completed_at);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->id, 'action' => 'technical_assessment.section_closed', 'reason' => 'timeout']);
    }

    public function test_form_a_completion_does_not_offer_separate_notes_and_questionnaire_still_requires_same_application_form_a(): void
    {
        $this->freezeTime();
        $formA = $this->attempt('A', ['completed_at' => now()]);
        $this->loginFor($formA);
        $this->get(route('candidate.technical-assessments.show', $formA))->assertSee('Lanjutkan ke Form B')->assertDontSee('name="conditions[', false);
        $this->postJson(route('candidate.technical-assessments.update', $formA), ['action' => 'conditions', 'conditions' => ['technical_conditions' => 'Terlalu awal.'], 'confirmation' => true])->assertJsonValidationErrors('conditions');
        $this->assertNull($formA->fresh()->conditions);
        $formB = $this->attempt('B', ['consented_at' => now(), 'section_index' => 2]);
        $this->loginFor($formB);

        $this->postJson(route('candidate.technical-assessments.update', $formB), $this->payload($formB, 'start'))->assertUnprocessable();

        $this->assertNull($formB->fresh()->section_started_at);
        $this->assertSame([], $formB->fresh()->answers);
    }

    public function test_legacy_form_a_no_longer_offers_condition_confirmation_or_accepts_note_changes(): void
    {
        $this->freezeTime();
        $package = app(TechnicalAssessmentService::class)->packages()['A'];
        $package['condition_questions'] = app(TechnicalAssessmentService::class)->packages()['B']['condition_questions'];
        $package['version'] = '2026-10-21.1';
        $attempt = $this->attempt('A', ['package' => $package, 'version' => $package['version'], 'completed_at' => now(), 'conditions' => ['technical_conditions' => 'Catatan lama tetap tersimpan.']]);
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Lanjutkan ke Form B')
            ->assertDontSee('Catatan kondisi dan konfirmasi')->assertDontSee('name="conditions[', false);
        $this->get(route('candidate.technical-assessments'))->assertSee('Lihat status tes')->assertDontSee('Konfirmasi dan catatan kondisi');
        $this->postJson(route('candidate.technical-assessments.update', $attempt), ['action' => 'conditions', 'conditions' => ['technical_conditions' => 'Catatan baru.'], 'confirmation' => true])->assertJsonValidationErrors('conditions');

        $this->assertSame(['technical_conditions' => 'Catatan lama tetap tersimpan.'], $attempt->fresh()->conditions);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.conditions_saved', 'target_id' => $attempt->id]);
    }

    public function test_activity_logs_and_conditions_are_authorized_validated_and_escaped(): void
    {
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinute(), 'revision' => 3]);
        $package = $attempt->package;
        $package['condition_questions'] = app(TechnicalAssessmentService::class)->packages()['B']['condition_questions'];
        $attempt->update(['package' => $package]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.activity', $attempt);
        foreach (['page_loaded', 'tab_hidden', 'window_blur', 'fullscreen_exit', 'connection_restored'] as $event) {
            $this->postJson($url, ['event' => $event, 'section' => 0])->assertNoContent();
        }
        $this->postJson($url, ['event' => 'custom', 'section' => 0])->assertUnprocessable();
        $this->postJson($url, ['event' => 'tab_hidden', 'section' => 1])->assertConflict();
        $this->assertSame(3, $attempt->fresh()->revision);
        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertSame(5, $attempt->activityLogs()->count());
        $attempt->update(['deadline' => now()]);
        $this->getJson(route('candidate.technical-assessments.show', $attempt))->assertJsonPath('completed', true);
        $this->postJson($url, ['event' => 'tab_hidden', 'section' => 0])->assertConflict();
        $this->postJson(route('candidate.technical-assessments.update', $attempt), ['action' => 'conditions', 'conditions' => ['technical_conditions' => '<script>alert(1)</script>'], 'confirmation' => true])->assertOk();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)->assertSee('Riwayat aktivitas sesi')->assertSee('bukan bukti kecurangan')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_activity_summary_counts_all_events_per_candidate_beyond_the_latest_history(): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $other = $this->attempt();
        $this->loginFor($attempt);
        foreach (['tab_hidden', 'tab_hidden', 'window_blur', 'fullscreen_exit'] as $event) {
            $this->postJson(route('candidate.technical-assessments.activity', $attempt), ['event' => $event, 'section' => 0])->assertNoContent();
        }
        AuditLog::record('technical_assessment.activity.tab_hidden', $other, $other->application->user);
        for ($index = 0; $index < 101; $index++) {
            AuditLog::record('technical_assessment.answers_saved', $attempt, $attempt->application->user);
        }

        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(TechnicalAssessments::class)
            ->assertSee('Catatan aktivitas')->assertSee('Beralih tab')->assertSee('Kehilangan fokus')->assertSee('Keluar layar penuh')
            ->assertViewHas('attempts', function ($attempts) use ($attempt, $other): bool {
                $summary = $attempts->getCollection()->firstWhere('id', $attempt->id);
                $otherSummary = $attempts->getCollection()->firstWhere('id', $other->id);

                return $summary->tab_hidden_count === 2 && $summary->window_blur_count === 1 && $summary->fullscreen_exit_count === 1 && $otherSummary->tab_hidden_count === 1;
            });
    }

    public function test_activity_from_another_candidate_is_rejected_without_a_log(): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinute()]);
        $this->loginFor($this->attempt());

        $this->postJson(route('candidate.technical-assessments.activity', $attempt), ['event' => 'tab_hidden', 'section' => 0])->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_extension_preserves_answers_and_revision_and_cannot_reopen_expired_stage(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 0, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15), 'answers' => [0 => ['A01' => 'C']]]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $expires = $attempt->section_expires_at->copy()->addMinutes(5);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)->set('extraMinutes', 5)->set('extensionReason', 'Koneksi terputus saat bagian penalaran.')->call('extend', 0, 0)->assertHasNoErrors();
        $this->assertSame($expires->toIso8601String(), $attempt->fresh()->section_expires_at->toIso8601String());
        $this->assertSame(0, $attempt->fresh()->revision);
        $this->assertSame([0 => ['A01' => 'C']], $attempt->fresh()->answers);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_assessment.time_extended', 'reason' => 'Koneksi terputus saat bagian penalaran.']);
        $this->travel(20)->minutes();
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)->set('extensionReason', 'Permintaan memperpanjang tahap lama.')->call('extend', 0, 0)->assertConflict();
        $this->assertSame(1, $attempt->fresh()->section_index);
    }

    #[TestWith(['A'])]
    #[TestWith(['B'])]
    public function test_test_schedule_and_purged_application_cannot_be_bypassed(string $form): void
    {
        $attempt = $this->attempt($form, ['consented_at' => now(), 'opens_at' => now()->addHour()]);
        if ($form !== 'A') {
            $this->completeFormAFor($attempt);
        }
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertForbidden();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Jadwal belum dibuka')->assertDontSee('A01');
        $attempt->application->update(['purged_at' => now()]);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertNotFound();
        $this->assertNull($attempt->fresh()->section_started_at);
    }

    #[TestWith(['B'])]
    #[TestWith(['A2'])]
    public function test_followup_forms_are_locked_when_form_a_is_missing(string $form): void
    {
        $this->freezeTime();
        $attempt = $this->attempt($form);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);

        $this->get(route('candidate.technical-assessments'))->assertSee('Selesaikan Form A terlebih dahulu')->assertDontSee(route('candidate.technical-assessments.show', $attempt));
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Tes terkunci')->assertDontSee('data-assessment-start', false);
        $this->postJson($url, ['action' => 'consent', 'consent' => true])->assertUnprocessable()->assertJsonPath('message', 'Selesaikan Form A terlebih dahulu sebelum memulai Form B');

        $this->assertNull($attempt->fresh()->consented_at);
        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['B'])]
    #[TestWith(['A2'])]
    public function test_unfinished_form_a_and_another_applications_completed_form_a_do_not_unlock_followup_forms(string $form): void
    {
        $this->freezeTime();
        $attempt = $this->attempt($form, ['consented_at' => now()]);
        $this->attempt('A', ['recruitment_application_id' => $attempt->recruitment_application_id]);
        $otherApplication = RecruitmentApplication::factory()->create(['user_id' => $attempt->application->user_id, 'active_user_id' => null]);
        $this->attempt('A', ['recruitment_application_id' => $otherApplication->id, 'completed_at' => now()]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'start'))->assertUnprocessable()->assertJsonPath('message', 'Selesaikan Form A terlebih dahulu sebelum memulai Form B');
        $this->get(route('candidate.technical-assessments'))->assertSee('Selesaikan Form A terlebih dahulu')->assertDontSee(route('candidate.technical-assessments.show', $attempt));

        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertNull($attempt->fresh()->section_expires_at);
        $this->assertSame(0, $attempt->fresh()->revision);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_finishing_form_a_unlocks_form_b_and_form_a2_without_requiring_form_b_first(): void
    {
        $this->freezeTime();
        $formA = $this->attempt('A', ['consented_at' => now(), 'section_index' => 3, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(10)]);
        $formB = $this->attempt('B', ['recruitment_application_id' => $formA->recruitment_application_id]);
        $formA2 = $this->attempt('A2', ['recruitment_application_id' => $formA->recruitment_application_id]);
        $this->loginFor($formA);

        $this->postJson(route('candidate.technical-assessments.update', $formA), $this->payload($formA, 'finish'))->assertJsonPath('completed', true);
        $this->get(route('candidate.technical-assessments'))->assertDontSee('Selesaikan Form A terlebih dahulu')->assertSee(route('candidate.technical-assessments.show', $formB))->assertSee(route('candidate.technical-assessments.show', $formA2));
        foreach ([$formA2, $formB] as $attempt) {
            $url = route('candidate.technical-assessments.update', $attempt);
            $this->postJson($url, ['action' => 'consent', 'consent' => true])->assertOk();
            $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('data-assessment-start', false)->assertDontSee('Tes terkunci');
            $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('active', true);
            $this->assertNotNull($attempt->fresh()->section_started_at);
        }
    }

    public function test_assessor_cannot_bypass_form_a_requirement_when_starting_form_b(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now()]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)
            ->assertSee('Kandidat harus menyelesaikan Form A terlebih dahulu')->assertDontSee('Mulai tahap untuk kandidat')
            ->set('confirmStageAction', true)->call('control', 0, 0, 'start')->assertUnprocessable();

        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_existing_active_form_b_stage_can_be_saved_and_finished_but_next_stage_requires_form_a(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(10)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $answers = $this->simulationAnswers();

        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('data-assessment-form', false)->assertDontSee('Tes terkunci');
        $this->postJson($url, $this->payload($attempt, 'save', $answers))->assertOk();
        $this->postJson($url, $this->payload($attempt, 'next', $answers))->assertJsonPath('simulation_locked', true);
        $this->postJson($url, $this->payload($attempt, 'finish'))->assertJsonPath('section', 2)->assertJsonPath('active', false);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertUnprocessable();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Tes terkunci');

        $this->assertSame($answers, $attempt->fresh()->answers[1]);
        $this->assertNull($attempt->fresh()->section_started_at);
    }

    #[TestWith(['B'])]
    #[TestWith(['A2'])]
    public function test_completed_followup_forms_keep_condition_notes_accessible_without_form_a(string $form): void
    {
        $this->freezeTime();
        $attempt = $this->attempt($form, ['completed_at' => now()]);
        $package = $attempt->package;
        $package['condition_questions'] = app(TechnicalAssessmentService::class)->packages()['B']['condition_questions'];
        $attempt->update(['package' => $package]);
        $this->loginFor($attempt);

        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Catatan kondisi dan konfirmasi')->assertDontSee('Tes terkunci');
        $this->postJson(route('candidate.technical-assessments.update', $attempt), ['action' => 'conditions', 'conditions' => ['technical_conditions' => 'Koneksi stabil.'], 'confirmation' => true])->assertOk();

        $this->assertSame('Koneksi stabil.', $attempt->fresh()->conditions['technical_conditions']);
    }
}
