<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Psychometrics;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest as Assessment;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\PsychometricService;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PsychometricTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_is_recorded_without_changing_answers_or_revision_and_is_visible_to_hr(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['section_started_at' => now(), 'section_expires_at' => now()->addMinute(), 'revision' => 7, 'answers' => [[1 => ['A']]]]);
        $this->loginFor($attempt);
        foreach (['tab_hidden', 'window_blur', 'fullscreen_exit'] as $event) {
            $this->postJson(route('candidate.psychometrics.activity', $attempt), ['event' => $event, 'section' => 0])->assertNoContent();
            $this->assertDatabaseHas('audit_logs', ['target_id' => $attempt->id, 'actor_id' => $attempt->application->user_id, 'action' => 'psychometric.activity.'.$event]);
        }
        $this->assertSame(7, $attempt->fresh()->revision);
        $this->assertSame([[1 => ['A']]], $attempt->fresh()->answers);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Livewire::actingAs($admin)->test(Psychometrics::class)->assertSee('Ringkasan aktivitas tes')->assertSee('bukan bukti kecurangan');
        $this->assertSame(3, $attempt->activityLogs()->count());
    }

    public function test_activity_rejects_invalid_events_other_candidates_and_inactive_sections(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['section_started_at' => now(), 'section_expires_at' => now()->addMinute()]);
        $url = route('candidate.psychometrics.activity', $attempt);
        $payload = ['event' => 'tab_hidden', 'section' => 0];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->loginFor(PsychometricAttempt::factory()->create());
        $this->postJson($url, $payload)->assertNotFound();
        $this->loginFor($attempt);
        $this->postJson($url, ['event' => 'arbitrary', 'section' => 0])->assertUnprocessable();
        $this->postJson($url, ['event' => 'tab_hidden', 'section' => 1])->assertConflict();
        $attempt->update(['section_expires_at' => now()->subSecond()]);
        $this->postJson($url, $payload)->assertConflict();
        $attempt->update(['section_expires_at' => now()->addMinute(), 'completed_at' => now()]);
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_rename_draft_and_published_packages_without_changing_test_data(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $draft = Assessment::factory()->create();
        $attempt = PsychometricAttempt::factory()->create();
        $attemptAttributes = $attempt->fresh()->getAttributes();

        foreach ([$draft, $attempt->test] as $test) {
            $attributes = $test->fresh()->getAttributes();
            $page = Livewire::actingAs($admin)->test(Psychometrics::class)
                ->call('edit', $test->id)->assertSet('packageTitle', $test->title)
                ->set('packageTitle', '  Paket rekrutmen '.$test->id.'  ')
                ->call('renamePackage')->assertHasNoErrors()
                ->assertSet('packageTitle', 'Paket rekrutmen '.$test->id)
                ->assertSee('Nama paket tes berhasil disimpan.')
                ->assertViewHas('selected', fn ($selected) => $selected->title === 'Paket rekrutmen '.$test->id);

            $renamed = $test->fresh();
            foreach (['sections', 'answer_key', 'published_at'] as $attribute) {
                $this->assertSame($attributes[$attribute], $renamed->getAttributes()[$attribute]);
            }
            $log = AuditLog::where('action', 'psychometric.renamed')->where('target_id', $test->id)->sole();
            $this->assertSame($admin->id, $log->actor_id);
            $this->assertSame(['previous_title' => $attributes['title'], 'title' => $renamed->title], $log->metadata);
            $page->call('renamePackage')->assertHasNoErrors();
        }

        $this->assertSame($attemptAttributes, $attempt->fresh()->getAttributes());
        $this->assertSame(2, AuditLog::where('action', 'psychometric.renamed')->count());
    }

    public function test_package_rename_validates_name_and_requires_selection_and_active_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->create();
        Livewire::actingAs($admin)->test(Psychometrics::class)->call('renamePackage')->assertStatus(422);
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id);
        $page->set('packageTitle', '   ')->call('renamePackage')->assertHasErrors(['packageTitle' => 'required']);
        $page->set('packageTitle', str_repeat('a', 256))->call('renamePackage')->assertHasErrors(['packageTitle' => 'max']);
        $this->assertSame($test->title, $test->fresh()->title);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.renamed']);

        $page->set('packageTitle', 'Tidak diizinkan');
        $admin->update(['active' => false]);
        $page->call('renamePackage')->assertForbidden();
        $this->assertSame($test->title, $test->fresh()->title);
    }

    public function test_results_filter_by_position_period_and_candidate_and_reset_pagination(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $match = PsychometricAttempt::factory()->create();
        $match->application->position->update(['active' => false]);
        $other = PsychometricAttempt::factory()->create();
        $other->application->update(['recruitment_period_id' => $match->application->recruitment_period_id]);
        $match->application->period->update(['active' => false]);
        $samePosition = PsychometricAttempt::factory()->create([
            'recruitment_application_id' => RecruitmentApplication::factory()->create([
                'position_id' => $match->application->position_id,
            ])->id,
        ]);

        $page = Livewire::actingAs($admin)->test(Psychometrics::class)
            ->call('setPage', 2)
            ->set('positionFilter', (string) $match->application->position_id)
            ->assertSet('paginators.page', 1)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 2 && ! $attempts->contains('id', $other->id))
            ->set('resultSearch', $match->application->user->email)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->modelKeys() === [$match->id])
            ->set('resultSearch', $samePosition->application->user->name)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->modelKeys() === [$samePosition->id])
            ->set('resultSearch', $other->application->user->email)
            ->assertSee('Tidak ada penugasan yang cocok')
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 0)
            ->assertViewHas('attemptCount', 3);

        $page->call('resetResultFilters')
            ->assertSet('resultSearch', '')->assertSet('positionFilter', '')
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 3);

        $page->call('setPage', 2)
            ->set('periodFilter', (string) $match->application->recruitment_period_id)
            ->assertSet('paginators.page', 1)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 2 && ! $attempts->contains('id', $samePosition->id))
            ->set('positionFilter', (string) $match->application->position_id)
            ->set('resultSearch', $match->application->user->email)
            ->assertViewHas('attempts', fn ($attempts) => $attempts->modelKeys() === [$match->id])
            ->call('resetResultFilters')->assertSet('periodFilter', '')
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 3)
            ->set('periodFilter', '999999')
            ->assertSee('Tidak ada penugasan yang cocok')
            ->assertViewHas('attempts', fn ($attempts) => $attempts->total() === 0);
    }

    public function test_bulk_assignment_keeps_search_selection_and_skips_existing_attempts(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $existing = PsychometricAttempt::factory()->create();
        $applications = RecruitmentApplication::factory()->count(2)->create();
        $ids = [$existing->recruitment_application_id, ...$applications->modelKeys()];

        Livewire::actingAs($admin)->test(Psychometrics::class)
            ->call('edit', $existing->psychometric_test_id)
            ->set('applicationIds', $ids)->set('search', 'no-matching-candidate')
            ->assertSet('applicationIds', $ids)->assertSee('Tidak ada lamaran kandidat aktif yang cocok.')
            ->set('opensAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('deadline', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->call('assign')->assertHasNoErrors()->assertSet('applicationIds', [])
            ->assertSee('2 penugasan berhasil dibuat. 1 penugasan yang sudah ada dilewati');

        $this->assertDatabaseCount('psychometric_attempts', 3);
        $this->assertTrue($existing->deadline->equalTo($existing->fresh()->deadline));
        foreach ($applications as $application) {
            $this->assertDatabaseHas('psychometric_attempts', [
                'psychometric_test_id' => $existing->psychometric_test_id,
                'recruitment_application_id' => $application->id,
                'deadline' => now()->addDays(3)->startOfMinute()->subHours(8)->toDateTimeString(),
            ]);
        }
        $this->assertSame(2, AuditLog::where('action', 'psychometric.assigned')->count());
    }

    public function test_bulk_assignment_rejects_empty_and_duplicate_selections(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->published()->create();
        $application = RecruitmentApplication::factory()->create();

        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id);
        $page->call('assign')->assertHasErrors('applicationIds');
        $page->set('applicationIds', [$application->id, $application->id])
            ->call('assign')->assertHasErrors('applicationIds.0');

        $this->assertDatabaseCount('psychometric_attempts', 0);
    }

    public function test_bulk_assignment_rolls_back_when_a_later_candidate_is_inactive(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->published()->create();
        $first = RecruitmentApplication::factory()->create();
        $second = RecruitmentApplication::factory()->create();
        $second->user->update(['active' => false]);

        Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id)
            ->set('applicationIds', [$first->id, $second->id])
            ->set('opensAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('deadline', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->call('assign')->assertStatus(422);

        $this->assertDatabaseCount('psychometric_attempts', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.assigned']);
    }

    public function test_hr_can_remove_a_participant_and_results_only_from_the_selected_test(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = PsychometricAttempt::factory()->create(['completed_at' => now(), 'answers' => [[1 => ['A']]], 'raw_score' => 1, 'section_scores' => [1]]);
        $application = $attempt->application;
        $otherTest = PsychometricAttempt::factory()->create(['recruitment_application_id' => $application->id]);
        $otherParticipant = PsychometricAttempt::factory()->create(['psychometric_test_id' => $attempt->psychometric_test_id]);

        $page = Livewire::actingAs($admin)->test(Psychometrics::class)
            ->call('confirmAttemptDeletion', $attempt->id)->assertSee('Ya, hapus peserta dari tes');
        $this->assertModelExists($attempt);
        $page->call('cancelAttemptDeletion')->assertSet('deletingAttemptId', null);
        $this->assertModelExists($attempt);
        $page->call('confirmAttemptDeletion', $attempt->id)->call('deleteAttempt')
            ->assertHasNoErrors()->assertSet('deletingAttemptId', null)->assertSee('Peserta berhasil dihapus');

        $this->assertModelMissing($attempt);
        $this->assertModelExists($application);
        $this->assertModelExists($application->user);
        $this->assertModelExists($attempt->test);
        $this->assertModelExists($otherTest);
        $this->assertModelExists($otherParticipant);
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychometric.attempt_deleted', 'actor_id' => $admin->id, 'target_id' => $attempt->id]);
        $this->loginFor($attempt);
        $this->expectException(ModelNotFoundException::class);
        app(PsychometricService::class)->act($application->user, $attempt->id, 'view');
    }

    public function test_attempt_deletion_requires_confirmation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = PsychometricAttempt::factory()->create();
        Livewire::actingAs($admin)->test(Psychometrics::class)->call('deleteAttempt')->assertStatus(422);
        $this->assertModelExists($attempt);
    }

    public function test_candidate_cannot_delete_their_own_attempt(): void
    {
        $attempt = PsychometricAttempt::factory()->create();
        try {
            app(PsychometricService::class)->deleteAttempt($attempt->application->user, $attempt->id);
            $this->fail('Candidate deletion must be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertModelExists($attempt);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.attempt_deleted']);
    }

    private function loginFor(PsychometricAttempt $attempt): void
    {
        $user = $attempt->application->user;
        $this->actingAs($user)->withSession(['portal_session_version' => $user->session_version]);
    }

    public function test_iq_conversion_matches_the_supplied_table_and_category_boundaries(): void
    {
        $expected = [38, 40, 43, 45, 47, 48, 52, 55, 57, 60, 63, 67, 70, 72, 75, 78, 81, 85, 88, 91, 94, 96, 100, 103, 106, 109, 113, 116, 119, 121, 124, 128, 131, 133, 137, 140, 142, 145, 149, 152, 155, 157, 161, 165, 167, 169, 173, 176, 179, 183, 183];
        $attempt = new PsychometricAttempt(['completed_at' => now()]);
        foreach ($expected as $raw => $iq) {
            $attempt->raw_score = $raw;
            $this->assertSame($iq, $attempt->iqScore(), 'Raw score '.$raw);
        }

        foreach ([11 => 'Mentally Retardation', 12 => 'Borderline Defective', 15 => 'Borderline Defective', 16 => 'Low Average', 18 => 'Low Average', 19 => 'Average', 25 => 'Average', 26 => 'High Average', 28 => 'High Average', 29 => 'Superior', 34 => 'Superior', 35 => 'Very Superior', 45 => 'Very Superior', 46 => 'Genius', 50 => 'Genius'] as $raw => $category) {
            $attempt->raw_score = $raw;
            $this->assertSame($category, $attempt->iqCategory());
        }

        foreach ([null, -1, 51] as $raw) {
            $attempt->raw_score = $raw;
            $this->assertNull($attempt->iqScore());
            $this->assertNull($attempt->iqCategory());
        }
        $attempt->raw_score = 22;
        $attempt->completed_at = null;
        $this->assertNull($attempt->iqScore());
        $this->assertNull($attempt->iqCategory());
    }

    public function test_hr_can_replace_question_and_option_images_and_publish_without_losing_them(): void
    {
        Storage::fake('private');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->published()->create(['published_at' => null]);
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id);
        $page->set('questionImage', UploadedFile::fake()->image('question.png'))
            ->call('uploadQuestionImage')->assertHasNoErrors();
        $stem = $test->fresh()->sections[0]['images'][1]['stem'];
        $page->set('imagePart', 'A')->set('questionImage', UploadedFile::fake()->image('option.jpg'))
            ->call('uploadQuestionImage')->assertHasNoErrors();
        $option = $test->fresh()->sections[0]['images'][1]['A'];
        $page->set('reviewed', true)->call('save', true)->assertHasNoErrors();

        $this->assertNotNull($test->fresh()->published_at);
        $this->assertSame(['stem' => $stem, 'A' => $option], $test->fresh()->sections[0]['images'][1]);
        Storage::disk('private')->assertExists([$stem, $option]);
        $this->withSession(['portal_session_version' => $admin->session_version]);
        $this->get(route('psychometrics.question-preview', [$test, 0, 1, 'stem']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_image_replacement_rejects_published_packages_invalid_files_and_unknown_questions(): void
    {
        Storage::fake('private');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->published()->create();
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id);
        $page->set('questionImage', UploadedFile::fake()->image('question.png'))
            ->call('uploadQuestionImage')->assertHasErrors('questionImage');
        $draft = Assessment::factory()->create();
        $page->call('edit', $draft->id)->set('questionImage', UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf'))
            ->call('uploadQuestionImage')->assertHasErrors('questionImage');
        $page->set('imageQuestion', 99)->set('questionImage', UploadedFile::fake()->image('question.png'))
            ->call('uploadQuestionImage')->assertHasErrors('questionImage');
        $this->assertEmpty($draft->fresh()->sections[0]['images'] ?? []);
        $this->assertSame([], Storage::disk('private')->allFiles('psychometrics/questions'));
    }

    public function test_custom_images_are_private_and_only_available_for_the_owners_active_section(): void
    {
        Storage::fake('private');
        $this->freezeTime();
        $test = Assessment::factory()->published()->create();
        $sections = $test->sections;
        $path = UploadedFile::fake()->image('question.png')->store('psychometrics/questions', 'private');
        $sections[0]['images'][1]['stem'] = $path;
        $test->update(['sections' => $sections]);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $url = route('candidate.psychometrics.question-image', [$attempt, 0, 1, 'stem']);
        $this->get($url)->assertRedirect(route('login'));
        $this->loginFor($attempt);
        $this->get($url)->assertNotFound();
        $this->post(route('candidate.psychometrics.update', $attempt), ['action' => 'start', 'section' => 0]);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee($url, false);
        $this->get(route('psychometrics.question-preview', [$test, 0, 1, 'stem']))->assertForbidden();
        $other = PsychometricAttempt::factory()->create();
        $this->loginFor($other);
        $this->get($url)->assertNotFound();
        $this->loginFor($attempt);
        $attempt->update(['completed_at' => now()]);
        $this->get($url)->assertNotFound();
    }

    public function test_hr_can_delete_drafts_and_unassigned_published_packages_after_confirmation(): void
    {
        Storage::fake('private');
        $admin = User::factory()->create(['role' => Role::Admin]);
        foreach ([null, now()] as $publishedAt) {
            $test = Assessment::factory()->create(['published_at' => $publishedAt]);
            Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id)
                ->set('confirmDelete', true)->call('deletePackage')->assertHasNoErrors()->assertSet('testId', null);
            $this->assertModelMissing($test);
            $this->assertDatabaseHas('audit_logs', ['action' => 'psychometric.deleted', 'target_id' => $test->id]);
        }
    }

    public function test_deletion_is_refused_when_assignment_is_created_after_the_editor_was_opened(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = Assessment::factory()->published()->create();
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id)->set('confirmDelete', true);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $page->call('deletePackage')->assertHasErrors('deletePackage');
        $this->assertModelExists($test);
        $this->assertModelExists($attempt);
        $attempt->update(['completed_at' => now()]);
        $page->call('deletePackage')->assertHasErrors('deletePackage');
        $this->assertModelExists($attempt);
    }

    public function test_guests_and_other_candidates_cannot_read_or_change_an_attempt(): void
    {
        $attempt = PsychometricAttempt::factory()->create();
        $url = route('candidate.psychometrics.show', $attempt);
        $this->get($url)->assertRedirect(route('login'));
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['portal_session_version' => $other->session_version]);
        $this->get($url)->assertNotFound();
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertNotFound();
        $this->get(route('candidate.psychometrics.image', [$attempt, 2]))->assertNotFound();
        $this->assertNull($attempt->fresh()->section_started_at);
    }

    public function test_examples_are_available_before_start_but_question_pages_and_future_sections_are_private(): void
    {
        $attempt = PsychometricAttempt::factory()->create();
        $this->loginFor($attempt);
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Mulai bagian 1')->assertDontSee('answer_key');
        $this->get(route('candidate.psychometrics.image', [$attempt, 2]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('candidate.psychometrics.image', [$attempt, 3]))->assertNotFound();
        $this->post(route('candidate.psychometrics.update', $attempt), ['action' => 'start', 'section' => 0])->assertRedirect();
        $this->get(route('candidate.psychometrics.image', [$attempt, 3]))->assertOk();
        $this->get(route('candidate.psychometrics.image', [$attempt, 7]))->assertNotFound();
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('psych-question-card')->assertSee('Gambar pilihan A untuk soal 1')->assertDontSee('answer_key');
    }

    public function test_each_question_keeps_its_picture_choices_and_saved_answers_together(): void
    {
        foreach ([0 => [13, 6, 'radio'], 1 => [14, 5, 'checkbox'], 2 => [13, 6, 'radio'], 3 => [10, 5, 'radio']] as $section => [$count, $options, $type]) {
            $attempt = PsychometricAttempt::factory()->create([
                'section_index' => $section,
                'section_started_at' => now(),
                'section_expires_at' => now()->addMinute(),
                'answers' => [$section => [1 => ['A']]],
            ]);
            $this->loginFor($attempt);
            $response = $this->get(route('candidate.psychometrics.show', $attempt))->assertOk();
            $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
            $cards = $document->querySelectorAll('fieldset.psych-question-card');
            $this->assertCount($count, $cards);
            foreach ($cards as $index => $card) {
                $this->assertCount($options, $card->querySelectorAll('label svg'));
                $inputs = $card->querySelectorAll('input');
                $this->assertCount($options, $inputs);
                foreach ($inputs as $input) {
                    $this->assertSame($type, $input->getAttribute('type'));
                    $this->assertSame('answers['.($index + 1).'][]', $input->getAttribute('name'));
                    $this->assertSame($index === 0 && $input->getAttribute('value') === 'A', $input->hasAttribute('checked'));
                }
            }
        }
    }

    public function test_refresh_or_repeated_start_does_not_reset_the_timer(): void
    {
        $this->freezeTime();
        $attempt = PsychometricAttempt::factory()->create();
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.update', $attempt);
        $this->post($url, ['action' => 'start', 'section' => 0]);
        $expires = $attempt->fresh()->section_expires_at;
        $this->travel(20)->seconds();
        $this->post($url, ['action' => 'start', 'section' => 0])->assertRedirect();
        $this->assertTrue($attempt->fresh()->section_expires_at->equalTo($expires));
    }

    public function test_autosave_rejects_stale_revision_and_invalid_choice_without_overwriting_answers(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['section_started_at' => now(), 'section_expires_at' => now()->addMinute()]);
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.update', $attempt);
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['B']]])->assertJsonPath('revision', 1);
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['C']]])->assertConflict();
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 1, 'answers' => [1 => ['A', 'B']]])->assertUnprocessable();
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 1, 'answers' => [99 => ['A']]])->assertUnprocessable();
        $this->assertSame(['B'], $attempt->fresh()->answers[0][1]);
    }

    public function test_expired_section_rejects_late_answers_and_waits_before_starting_next_section(): void
    {
        $this->freezeTime();
        $attempt = PsychometricAttempt::factory()->create(['section_started_at' => now()->subMinute(), 'section_expires_at' => now(), 'answers' => [0 => [1 => ['A']]]]);
        $this->loginFor($attempt);
        $this->postJson(route('candidate.psychometrics.update', $attempt), ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['B']]])->assertConflict();
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Mulai bagian 2');
        $this->assertSame(['A'], $attempt->fresh()->answers[0][1]);
        $this->assertNull($attempt->fresh()->section_started_at);
        $this->assertSame(1, $attempt->fresh()->section_index);
    }

    public function test_final_submission_scores_exact_pairs_once_and_never_exposes_score_to_candidate(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['section_index' => 3, 'section_started_at' => now(), 'section_expires_at' => now()->addMinute(), 'answers' => [0 => [1 => ['A'], 2 => ['C']], 1 => [1 => ['B', 'A'], 2 => ['A']], 2 => [1 => ['A']]]]);
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.update', $attempt);
        $payload = ['action' => 'finish', 'section' => 3, 'revision' => 0, 'answers' => [1 => ['A']]];
        $this->postJson($url, $payload)->assertJsonPath('completed', true)->assertJsonMissingPath('raw_score');
        $this->postJson($url, $payload)->assertJsonPath('completed', true);
        $this->assertSame(4, $attempt->fresh()->raw_score);
        $this->assertSame([1, 1, 1, 1], $attempt->fresh()->section_scores);
        $this->assertSame(1, AuditLog::where('action', 'psychometric.completed')->count());
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Jawaban telah dikunci')->assertDontSee('Skor mentah');
        $this->get(route('candidate.psychometrics.image', [$attempt, 13]))->assertNotFound();
    }

    public function test_scheduler_finalizes_disconnected_attempts_and_is_idempotent(): void
    {
        $this->freezeTime();
        $attempt = PsychometricAttempt::factory()->create(['deadline' => now(), 'answers' => [0 => [1 => ['A']]]]);
        $this->artisan('psychometrics:finalize')->assertSuccessful();
        $this->artisan('psychometrics:finalize')->assertSuccessful();
        $this->assertNotNull($attempt->fresh()->completed_at);
        $this->assertSame(1, $attempt->fresh()->raw_score);
        $this->assertSame(1, AuditLog::where('action', 'psychometric.completed')->count());
    }

    public function test_future_and_archived_assignments_cannot_be_started(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['opens_at' => now()->addHour()]);
        $this->loginFor($attempt);
        $this->postJson(route('candidate.psychometrics.update', $attempt), ['action' => 'start', 'section' => 0])->assertForbidden();
        $attempt->application->update(['archived_at' => now()]);
        $this->get(route('candidate.psychometrics.show', $attempt))->assertNotFound();
        $this->assertNull($attempt->fresh()->section_started_at);
    }

    public function test_admin_can_publish_original_source_with_valid_keys_and_review(): void
    {
        Storage::fake('private');
        $test = Assessment::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $keys = array_map(fn (array $s): string => implode(' ', array_fill(0, $s['count'], $s['choices'] === 2 ? 'A,B' : 'A')), config('psychometrics.sections'));
        $component = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id)->set('durations', [60, 60, 60, 60])->set('keyText', $keys)->set('reviewed', true);
        $component->call('save', true)->assertHasNoErrors()->assertSee('Terbit');
        $this->assertNull($test->fresh()->corrected_page_path);
        $this->assertSame(13, $test->fresh()->sections[2]['count']);
        $this->assertNotNull($test->fresh()->published_at);
        $component->call('save')->assertStatus(409);
    }

    public function test_candidate_cannot_use_admin_page_or_preview(): void
    {
        $candidate = User::factory()->create();
        $test = Assessment::factory()->create();
        Livewire::actingAs($candidate)->test(Psychometrics::class)->assertForbidden();
        $this->actingAs($candidate)->withSession(['portal_session_version' => $candidate->session_version])->get(route('psychometrics.preview', [$test, 11]))->assertForbidden();
    }

    public function test_assignment_is_unique_and_does_not_reset_existing_deadline(): void
    {
        $this->freezeTime();
        $attempt = PsychometricAttempt::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service = app(PsychometricService::class);
        $same = $service->assign($admin, $attempt->psychometric_test_id, $attempt->recruitment_application_id, now()->toDateTimeString(), now()->addDays(2)->toDateTimeString());
        $this->assertSame($attempt->id, $same->id);
        $this->assertTrue($attempt->deadline->equalTo($same->deadline));
        $this->assertDatabaseCount('psychometric_attempts', 1);
    }

    public function test_publication_requires_duration_complete_keys_and_review_even_after_source_correction(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('corrected.jpg', 'image');
        $test = Assessment::factory()->create(['corrected_page_path' => 'corrected.jpg']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $component = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id);
        $component->call('save', true)->assertHasErrors('duration');
        $component->set('durations', [60, 60, 60, 60])->set('keyText', ['A'])->call('save', true)->assertHasErrors('keyText.0');
        $component->set('keyText', ['Z'])->call('save')->assertHasErrors('answers');
        $keys = array_map(fn (array $s): string => implode(' ', array_fill(0, $s['count'], $s['choices'] === 2 ? 'A,B' : 'A')), config('psychometrics.sections'));
        $component->set('keyText', $keys)->call('save', true)->assertHasErrors('reviewed');
        $this->assertNull($test->fresh()->published_at);
    }

    public function test_new_assignment_is_audited_and_visible_only_to_its_owner(): void
    {
        $this->freezeTime();
        $test = Assessment::factory()->published()->create();
        $application = RecruitmentApplication::factory()->create();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = app(PsychometricService::class)->assign($admin, $test->id, $application->id, now()->toDateTimeString(), now()->addDay()->toDateTimeString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychometric.assigned', 'target_id' => $attempt->id]);
        $this->loginFor($attempt);
        $this->get(route('candidate.psychometrics'))->assertSee($test->title);
    }

    public function test_retention_removes_answers_and_scores_only_after_enabled_purge(): void
    {
        $this->freezeTime();
        Storage::fake('private');
        $attempt = PsychometricAttempt::factory()->create(['answers' => [0 => [1 => ['A']]]]);
        $attempt->application->update(['archived_at' => now()->subYears(2)]);
        AppSetting::updateOrCreate(['key' => 'retention_enabled'], ['value' => true]);
        AppSetting::updateOrCreate(['key' => 'retention_months'], ['value' => 12]);
        $this->artisan('portal:cleanup', ['--dry-run' => true])->assertSuccessful();
        $this->assertModelExists($attempt);
        $this->artisan('portal:cleanup')->assertSuccessful();
        $this->assertModelMissing($attempt);
    }

    public function test_migration_resumes_after_test_table_was_created_without_losing_existing_data(): void
    {
        $test = Assessment::factory()->create();
        Schema::drop('psychometric_attempts');
        $migration = require database_path('migrations/2026_09_26_010459_create_psychometric_tables.php');

        $migration->up();

        $this->assertDatabaseHas('psychometric_tests', ['id' => $test->id, 'title' => $test->title]);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $this->assertModelExists($attempt);
    }

    public function test_hr_can_compare_ordered_keys_with_correct_incorrect_and_blank_answers(): void
    {
        $attempt = PsychometricAttempt::factory()->create([
            'completed_at' => now(),
            'raw_score' => 2,
            'section_scores' => [1, 1, 0, 0],
            'answers' => [0 => [1 => ['A'], 2 => ['C']], 1 => [1 => ['B', 'A']]],
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(Psychometrics::class)
            ->assertSee('Rincian jawaban dan kunci (khusus HR)')
            ->assertSee('IQ: 43')
            ->assertSee('Mentally Retardation')
            ->assertSeeInOrder(['Jawaban kandidat', 'Kunci jawaban', 'Benar', 'Salah', 'Tidak dijawab', 'A, B', 'A, B', 'Benar']);

        $this->loginFor($attempt);
        $this->get(route('candidate.psychometrics.show', $attempt))
            ->assertDontSee('Kunci jawaban')->assertDontSee('A, B')
            ->assertDontSee('IQ: 43')->assertDontSee('Mentally Retardation');
    }

    public function test_matrix_answers_follow_twelve_images_and_show_original_number_labels(): void
    {
        $legacy = config('psychometrics.sections');
        $legacy[2]['count'] = 12;
        $legacy[2]['source_numbers'] = [1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13];
        $legacy[2]['pages'] = [10, 11];
        $test = Assessment::factory()->published()->create(['sections' => $legacy]);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'section_index' => 2, 'section_started_at' => now(), 'section_expires_at' => now()->addMinute()]);
        $this->loginFor($attempt);
        $this->get(route('candidate.psychometrics.show', $attempt))
            ->assertSee('nomor 11 pada gambar')->assertSee('nomor 13 pada gambar')->assertDontSee('Soal 13');
        $this->postJson(route('candidate.psychometrics.update', $attempt), ['action' => 'save', 'section' => 2, 'revision' => 0, 'answers' => [13 => ['A']]])->assertUnprocessable();
        $this->postJson(route('candidate.psychometrics.update', $attempt), ['action' => 'save', 'section' => 2, 'revision' => 0, 'answers' => [12 => ['A']]])->assertJsonPath('revision', 1);
        $this->assertSame(['A'], $attempt->fresh()->answers[2][12]);
    }

    public function test_restored_question_has_six_choices_and_is_private_until_matrix_section_starts(): void
    {
        $attempt = PsychometricAttempt::factory()->create(['section_index' => 2]);
        $this->loginFor($attempt);
        $image = route('candidate.psychometrics.image', [$attempt, 14]);
        $this->get($image)->assertNotFound();
        $this->post(route('candidate.psychometrics.update', $attempt), ['action' => 'start', 'section' => 2])->assertRedirect();
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png');
        $response = $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Soal 13')->assertDontSee('nomor 11 pada gambar');
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $card = $document->querySelector('#question-10');
        $this->assertCount(6, $card->querySelectorAll('input[type="radio"]'));
        foreach ($card->querySelectorAll('svg image') as $element) {
            $this->assertSame($image, $element->getAttribute('href'));
            $this->assertSame('2048', $element->getAttribute('width'));
            $this->assertSame('684', $element->getAttribute('height'));
        }
        $this->postJson(route('candidate.psychometrics.update', $attempt), ['action' => 'save', 'section' => 2, 'revision' => 0, 'answers' => [10 => ['D'], 13 => ['A']]])->assertJsonPath('revision', 1);
        $this->assertSame(['D'], $attempt->fresh()->answers[2][10]);
    }

    public function test_restoration_shifts_only_unassigned_draft_keys_and_images_and_requires_missing_key(): void
    {
        $sections = config('psychometrics.sections');
        $sections[2] = [...$sections[2], 'count' => 12, 'source_numbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13], 'pages' => [10, 11], 'seconds' => 180, 'images' => [10 => ['A' => 'psychometrics/questions/existing.png']]];
        $keys = array_fill(0, 12, ['B']);
        $keys[9] = ['C'];
        $keys[11] = ['F'];
        $draft = Assessment::factory()->create(['sections' => $sections, 'answer_key' => [2 => $keys]]);
        $published = Assessment::factory()->published()->create(['sections' => $sections]);
        $assigned = Assessment::factory()->create(['sections' => $sections]);
        PsychometricAttempt::factory()->create(['psychometric_test_id' => $assigned->id]);
        $migration = require database_path('migrations/2026_09_27_030244_restore_missing_cfit_matrix_question.php');
        $migration->up();
        $migration->up();

        $this->assertSame(13, $draft->fresh()->sections[2]['count']);
        $this->assertSame(180, $draft->fresh()->sections[2]['seconds']);
        $this->assertSame([], $draft->fresh()->answer_key[2][9]);
        $this->assertSame(['C'], $draft->fresh()->answer_key[2][10]);
        $this->assertSame(['F'], $draft->fresh()->answer_key[2][12]);
        $this->assertSame('psychometrics/questions/existing.png', $draft->fresh()->sections[2]['images'][11]['A']);
        $this->assertSame($sections, $published->fresh()->sections);
        $this->assertSame($sections, $assigned->fresh()->sections);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $draft->id);
        $this->assertSame('B B B B B B B B B - C B F', $page->get('keyText')[2]);
        $page->call('save')->assertHasNoErrors();
        $this->assertSame([], $draft->fresh()->answer_key[2][9]);
    }

    public function test_numbering_correction_preserves_keys_durations_and_published_tests(): void
    {
        $sections = config('psychometrics.sections');
        $sections[2]['count'] = 13;
        $sections[2]['pages'] = [10, 11];
        $sections[2]['seconds'] = 180;
        unset($sections[2]['source_numbers']);
        $draft = Assessment::factory()->create(['sections' => $sections, 'answer_key' => [2 => [['B']]]]);
        $published = Assessment::factory()->published()->create(['sections' => $sections]);
        $migration = require database_path('migrations/2026_09_26_015748_correct_draft_psychometric_matrix_numbering.php');

        $migration->up();

        $this->assertSame(12, $draft->fresh()->sections[2]['count']);
        $this->assertSame(180, $draft->fresh()->sections[2]['seconds']);
        $this->assertSame([2 => [['B']]], $draft->fresh()->answer_key);
        $this->assertSame(13, $published->fresh()->sections[2]['count']);
    }

    public function test_ai_proposal_fills_empty_draft_with_private_review_notes_without_publishing(): void
    {
        $test = Assessment::factory()->create();

        $this->artisan('psychometrics:suggest-key', ['test' => $test->id])->assertSuccessful();

        $test->refresh();
        $this->assertSame([13, 14, 13, 10], array_map('count', $test->answer_key));
        $this->assertSame(['B', 'E'], $test->answer_key[1][0]);
        $this->assertSame('pending', $test->answer_key_review['status']);
        $this->assertSame('rendah', $test->answer_key_review['items'][2][12]['confidence']);
        $this->assertNull($test->published_at);
        $this->assertArrayNotHasKey('answer_key', $test->toArray());
        $this->assertArrayNotHasKey('answer_key_review', $test->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychometric.key_suggested', 'target_id' => $test->id]);
        Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))->test(Psychometrics::class)->call('edit', $test->id)
            ->assertSee('Kunci berurutan')->assertDontSee('Asal kunci:')->assertDontSee('Usulan awal AI dan alasan per soal')->assertDontSee('Skor adalah jumlah soal benar');
    }

    public function test_ai_proposal_never_overwrites_existing_keys_or_published_packages(): void
    {
        $draft = Assessment::factory()->create(['answer_key' => [[['C']]]]);
        $published = Assessment::factory()->published()->create(['answer_key' => []]);

        $this->artisan('psychometrics:suggest-key', ['test' => $draft->id])->assertFailed();
        $this->artisan('psychometrics:suggest-key', ['test' => $published->id])->assertFailed();

        $this->assertSame([[['C']]], $draft->fresh()->answer_key);
        $this->assertSame([], $published->fresh()->answer_key);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.key_suggested']);
    }

    public function test_ai_proposal_requires_hr_review_before_publication_and_records_the_reviewer(): void
    {
        $test = Assessment::factory()->create();
        $this->artisan('psychometrics:suggest-key', ['test' => $test->id])->assertSuccessful();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)->call('edit', $test->id)->set('durations', [60, 60, 60, 60]);

        $page->call('save', true)->assertHasErrors('keyText.2');
        $page->set('keyText.2', str_replace('-', 'A', $page->get('keyText')[2]));
        $page->call('save', true)->assertHasErrors('reviewed');
        $this->assertNull($test->fresh()->published_at);
        $page->set('reviewed', true)->call('save', true)->assertHasNoErrors();

        $this->assertSame('hr_reviewed', $test->fresh()->answer_key_review['status']);
        $this->assertSame($admin->id, $test->fresh()->answer_key_review['reviewed_by']);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $this->loginFor($attempt);
        $this->get(route('candidate.psychometrics.show', $attempt))->assertDontSee('ai_suggestion')->assertDontSee('Usulan awal AI')->assertDontSee('Keyakinan');
    }
}
