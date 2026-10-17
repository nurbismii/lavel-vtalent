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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TechnicalAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $form = 'A', array $attributes = []): TechnicalAssessmentAttempt
    {
        $package = app(TechnicalAssessmentService::class)->packages()[$form];

        return TechnicalAssessmentAttempt::factory()->create($attributes + ['form' => $form, 'package' => $package, 'version' => $package['version']]);
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
        $this->assertSame([300, 900, 900, 600], array_column($attempt->package['sections'], 'seconds'));
        $this->assertSame('C', array_key_first($attempt->package['scoring']['A01']));
        $attempt->update(['answers' => [1 => ['A01' => 'B']]]);

        $page->set('applicationIds', [$application->id])->call('assign')->assertHasNoErrors();

        $this->assertDatabaseCount('technical_assessment_attempts', 1);
        $this->assertSame([1 => ['A01' => 'B']], $attempt->fresh()->answers);
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.assigned')->count());
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
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $url = route('candidate.technical-assessments.show', $attempt);
        $this->getJson($url)->assertUnauthorized();
        $this->loginFor($this->attempt());
        $this->get($url)->assertNotFound();
        $this->loginFor($attempt);
        $this->get($url)->assertSee('A01')->assertSee('Tabel', false)->assertDontSee('D01')->assertDontSee('S01')->assertDontSee('Kunci dan Skoring')->assertDontSee('IPP =');
        $this->getJson($url)->assertExactJson(['section' => 1, 'revision' => 0, 'active' => true, 'completed' => false, 'remaining' => 900]);
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
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $payload = $this->payload($attempt, 'save', ['A01' => 'C', 'A02' => 'B']);
        $this->postJson($url, $payload)->assertJsonPath('revision', 1);
        $this->postJson($url, $payload + [])->assertConflict();
        $this->assertSame([1 => ['A01' => 'C', 'A02' => 'B']], $attempt->fresh()->answers);
        $this->postJson($url, $this->payload($attempt, 'save', ['A01' => 'C', 'A02' => 'B']))->assertJsonPath('revision', 1);
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.answers_saved')->count());
    }

    #[TestWith(['A01', 'Z'])]
    #[TestWith(['D01', 'A'])]
    #[TestWith(['A01', ['C']])]
    public function test_invalid_answers_are_rejected_without_writing(string $id, mixed $answer): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'save', [$id => $answer]))->assertUnprocessable();

        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertSame(0, $attempt->fresh()->revision);
    }

    public function test_timeout_commits_stage_transition_and_discards_late_answer(): void
    {
        $this->freezeTime();
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now()->subMinutes(15), 'section_expires_at' => now(), 'answers' => [1 => ['A01' => 'C']]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'save', ['A01' => 'B']))->assertJsonPath('section', 2)->assertJsonPath('active', false);

        $this->assertSame([1 => ['A01' => 'C']], $attempt->fresh()->answers);
        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_assessment.section_closed', 'reason' => 'timeout']);
    }

    public function test_completion_scores_manual_keys_and_sjt_weights_and_does_not_expose_result(): void
    {
        $answers = ['A01' => 'C', 'A02' => 'B', 'A03' => 'D', 'A04' => 'B', 'A05' => 'C', 'A06' => 'C', 'A07' => 'B', 'A08' => 'C', 'A09' => 'B', 'A10' => 'C', 'A11' => 'B', 'A12' => 'C'];
        $documents = ['D01' => 'A', 'D02' => 'B', 'D03' => 'C', 'D04' => 'C', 'D05' => 'B', 'D06' => 'C', 'D07' => 'D'];
        $sjt = ['S01' => 'B', 'S02' => 'B', 'S03' => 'C', 'S04' => 'B', 'S05' => 'B'];
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 3, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(10), 'answers' => [1 => $answers, 2 => $documents]]);
        $this->loginFor($attempt);

        $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'finish', $sjt))->assertJsonPath('completed', true)->assertJsonMissingPath('scores');

        $scores = $attempt->fresh()->scores;
        $this->assertSame(12, $scores['Penalaran']);
        $this->assertSame(4, $scores['Verbal']);
        $this->assertSame(4, $scores['Numerik']);
        $this->assertSame(4, $scores['Logis']);
        $this->assertSame(7, $scores['Dokumen']);
        $this->assertSame(7, $scores['SJT']);
        $this->assertSame(87, $scores['IPP pilot']);
        $this->assertSame([], $scores['Perlu verifikasi']);
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
        $attempt = $this->attempt('B', ['consented_at' => now(), 'section_index' => 2]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('active', true);
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('keputusan awal')->assertDontSee('berpotensi disusupi');
        $this->postJson($url, $this->payload($attempt, 'finish', ['priority_1' => 'Tahan pembayaran dan verifikasi independen.']))->assertJsonPath('section', 3)->assertJsonPath('active', false);
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertDontSee('berpotensi disusupi');
        $this->postJson($url, $this->payload($attempt, 'start'))->assertJsonPath('section', 3)->assertJsonPath('active', true);
        $attempt->refresh();

        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('berpotensi disusupi')->assertSee('Tahan pembayaran dan verifikasi independen.');
        $this->postJson($url, ['action' => 'save', 'section' => 2, 'revision' => $attempt->revision, 'answers' => ['priority_1' => 'Ubah jawaban']])->assertConflict();
        $this->assertSame('Tahan pembayaran dan verifikasi independen.', $attempt->fresh()->answers[2]['priority_1']);
    }

    public function test_form_b_candidates_progress_independently_without_assessor_actions(): void
    {
        $this->freezeTime();
        $first = $this->attempt('B', ['consented_at' => now()]);
        $second = $this->attempt('B', ['consented_at' => now()]);
        foreach ([$first, $second] as $attempt) {
            $this->loginFor($attempt);
            $this->postJson(route('candidate.technical-assessments.update', $attempt), $this->payload($attempt, 'start'))->assertJsonPath('active', true);
        }
        $secondExpires = $second->fresh()->section_expires_at->toIso8601String();
        $this->loginFor($first);
        $url = route('candidate.technical-assessments.update', $first);
        for ($section = 0; $section < 5; $section++) {
            $this->postJson($url, $this->payload($first, 'start'))->assertJsonPath('section', $section)->assertJsonPath('active', true);
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
        $attempt = $this->attempt('B', ['deadline' => now(), 'answers' => [1 => ['B01' => 'B', 'B02' => 'C', 'B03' => 'A', 'B04' => 'C']]]);
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

    public function test_activity_logs_and_conditions_are_authorized_validated_and_escaped(): void
    {
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_started_at' => now(), 'section_expires_at' => now()->addMinute(), 'revision' => 3]);
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
        $attempt = $this->attempt('A', ['consented_at' => now(), 'section_index' => 1, 'section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15), 'answers' => [1 => ['A01' => 'C']]]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $expires = $attempt->section_expires_at->copy()->addMinutes(5);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)->set('extraMinutes', 5)->set('extensionReason', 'Koneksi terputus saat bagian penalaran.')->call('extend', 1, 0)->assertHasNoErrors();
        $this->assertSame($expires->toIso8601String(), $attempt->fresh()->section_expires_at->toIso8601String());
        $this->assertSame(0, $attempt->fresh()->revision);
        $this->assertSame([1 => ['A01' => 'C']], $attempt->fresh()->answers);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_assessment.time_extended', 'reason' => 'Koneksi terputus saat bagian penalaran.']);
        $this->travel(20)->minutes();
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)->call('selectAttempt', $attempt->id)->set('extensionReason', 'Permintaan memperpanjang tahap lama.')->call('extend', 1, 0)->assertConflict();
        $this->assertSame(2, $attempt->fresh()->section_index);
    }

    #[TestWith(['A'])]
    #[TestWith(['B'])]
    public function test_test_schedule_and_purged_application_cannot_be_bypassed(string $form): void
    {
        $attempt = $this->attempt($form, ['consented_at' => now(), 'opens_at' => now()->addHour()]);
        $this->loginFor($attempt);
        $url = route('candidate.technical-assessments.update', $attempt);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertForbidden();
        $this->get(route('candidate.technical-assessments.show', $attempt))->assertSee('Jadwal belum dibuka')->assertDontSee('A01');
        $attempt->application->update(['purged_at' => now()]);
        $this->postJson($url, $this->payload($attempt, 'start'))->assertNotFound();
        $this->assertNull($attempt->fresh()->section_started_at);
    }
}
