<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\AuditLog;
use App\Models\Position;
use App\Models\PsychometricAttempt;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_filters_and_summary_use_online_attempts_and_ignore_legacy_upload_deadlines(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $waiting = TechnicalAssessmentAttempt::factory()->create();
        $active = TechnicalAssessmentAttempt::factory()->create(['section_started_at' => now(), 'section_expires_at' => now()->addMinutes(15)]);
        $completed = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);
        Submission::factory()->create(['recruitment_application_id' => $completed->recruitment_application_id, 'type' => 'technical_test', 'status' => 'submitted']);
        Submission::factory()->create(['recruitment_application_id' => $waiting->recruitment_application_id, 'type' => 'technical_test', 'deadline' => now()->subDay()]);

        $page = Livewire::actingAs($admin)->test(Recruitment::class)
            ->assertViewHas('stats', fn ($stats) => $stats['test'] === 1 && $stats['overdue'] === 0);
        foreach (['waiting' => $waiting, 'in_progress' => $active, 'completed' => $completed] as $status => $attempt) {
            $page->set('testFilter', $status)->assertViewHas('applications', fn ($applications) => $applications->getCollection()->modelKeys() === [$attempt->recruitment_application_id]);
        }
        $page->set('testFilter', '')->set('overdue', true)->assertViewHas('applications', fn ($applications) => $applications->isEmpty());
    }

    #[TestWith([[]])]
    #[TestWith([null])]
    #[TestWith([[0 => [], 1 => ['D01' => '', 'D02' => null]]])]
    public function test_completed_technical_filters_distinguish_saved_answers_from_blank_autosaves(?array $blankAnswers): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $answered = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now(), 'answers' => [2 => ['S01' => '0']]]);
        $unanswered = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now(), 'answers' => $blankAnswers]);
        TechnicalAssessmentAttempt::factory()->create(['answers' => [0 => ['A01' => 'C']], 'section_started_at' => now()]);
        TechnicalAssessmentAttempt::factory()->create();
        RecruitmentApplication::factory()->create();

        $page = Livewire::actingAs($admin)->test(Recruitment::class)
            ->set('testFilter', 'completed_answered')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$answered->recruitment_application_id])
            ->assertSee('Form A · Tes ditutup · jawaban tersimpan')
            ->set('testFilter', 'completed_unanswered')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$unanswered->recruitment_application_id])
            ->assertSee('Form A · Tes ditutup · tanpa jawaban tersimpan');

        $page->set('testFilter', 'completed')->assertViewHas('applications', fn ($applications): bool => $applications->total() === 2);
    }

    public function test_completed_technical_filters_match_each_form_on_the_same_application(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $answered = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now(), 'answers' => [0 => ['A01' => 'C']]]);
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $answered->recruitment_application_id, 'form' => 'B', 'completed_at' => now()]);

        Livewire::actingAs($admin)->test(Recruitment::class)
            ->set('testFilter', 'completed_answered')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$answered->recruitment_application_id])
            ->assertSee('Form A · Tes ditutup · jawaban tersimpan')->assertSee('Form B · Tes ditutup · tanpa jawaban tersimpan')
            ->set('testFilter', 'completed_unanswered')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$answered->recruitment_application_id]);
    }

    public function test_psychometric_status_filter_requires_a_closed_attempt_with_a_saved_answer(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $completed = PsychometricAttempt::factory()->create(['completed_at' => now(), 'answers' => [0 => [1 => ['A']]]]);
        $waiting = PsychometricAttempt::factory()->create(['recruitment_application_id' => $completed->recruitment_application_id]);
        $unanswered = PsychometricAttempt::factory()->create(['completed_at' => now(), 'deadline' => now()->subMinute(), 'answers' => [0 => [1 => []]]]);
        $inProgress = PsychometricAttempt::factory()->create(['section_index' => 1, 'answers' => [0 => [1 => ['B']]]]);
        $unassigned = RecruitmentApplication::factory()->create();

        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'applications')
            ->set('psychometricFilter', 'completed_answered')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$completed->recruitment_application_id])
            ->assertSee($completed->test->title)->assertSeeHtml('<span class="badge">Selesai dengan jawaban</span>')
            ->assertSee($waiting->test->title)->assertSeeHtml('<span class="badge">Belum mulai</span>')
            ->set('psychometricFilter', 'incomplete')
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->sortBy('id')->modelKeys() === [$unanswered->recruitment_application_id, $inProgress->recruitment_application_id, $unassigned->id])
            ->assertSeeHtml('<span class="badge">Ditutup tanpa jawaban</span>')->assertSeeHtml('<span class="badge">Sedang mengerjakan</span>')
            ->call('resetApplicationFilters')->assertSet('psychometricFilter', '')
            ->assertViewHas('applications', fn ($applications): bool => $applications->total() === 4);
    }

    public function test_position_can_change_without_an_upload_task_but_is_locked_after_online_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $application = RecruitmentApplication::factory()->create();
        $position = Position::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $application->id)
            ->set('assignment.position_id', $position->id)->set('reason', 'Perubahan posisi sebelum tes')
            ->call('updateAssignment')->assertHasNoErrors();
        $this->assertSame($position->id, $application->fresh()->position_id);
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $application->id]);

        $page->set('assignment.position_id', Position::factory()->create()->id)
            ->set('assignment.recruitment_period_id', RecruitmentPeriod::factory()->create()->id)
            ->set('reason', 'Perubahan posisi dan batch')->call('updateAssignment')->assertStatus(422);

        $this->assertSame($position->id, $application->fresh()->position_id);
        $this->assertSame($application->recruitment_period_id, $application->fresh()->recruitment_period_id);
    }

    public function test_admin_can_move_an_application_with_tests_to_an_active_batch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $batchOne = RecruitmentPeriod::factory()->create(['name' => 'Batch 1']);
        $batchTwo = RecruitmentPeriod::factory()->create(['name' => 'Batch 2']);
        $inactiveBatch = RecruitmentPeriod::factory()->create(['name' => 'Batch nonaktif', 'active' => false]);
        $application = RecruitmentApplication::factory()->for($batchOne, 'period')->create();
        $technical = TechnicalAssessmentAttempt::factory()->for($application, 'application')->create();
        $psychometric = PsychometricAttempt::factory()->for($application, 'application')->create();
        $reason = 'Kandidat dipindahkan ke batch 2';

        Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $application->id)
            ->assertSee('Batch rekrutmen')->assertSee('Pertahankan Batch 1')->assertDontSee('Batch nonaktif')
            ->set('reason', $reason)->call('updateAssignment')->assertHasErrors('assignment')
            ->set('assignment.recruitment_period_id', (string) $inactiveBatch->id)->call('updateAssignment')->assertHasErrors('assignment.recruitment_period_id')
            ->set('assignment.recruitment_period_id', (string) $batchTwo->id)->call('updateAssignment')->assertHasNoErrors()
            ->assertSee('Penugasan diperbarui.')->assertSee('Pertahankan Batch 2')
            ->call('navigate', 'applications')->set('periodFilter', (string) $batchOne->id)
            ->assertViewHas('applications', fn ($applications): bool => $applications->isEmpty())
            ->set('periodFilter', (string) $batchTwo->id)
            ->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$application->id]);

        $this->assertDatabaseHas('recruitment_applications', ['id' => $application->id, 'position_id' => $application->position_id, 'recruitment_period_id' => $batchTwo->id]);
        $this->assertDatabaseHas('technical_assessment_attempts', ['id' => $technical->id, 'recruitment_application_id' => $application->id]);
        $this->assertDatabaseHas('psychometric_attempts', ['id' => $psychometric->id, 'recruitment_application_id' => $application->id]);
        $audit = AuditLog::where('action', 'application.assignment_changed')->where('target_id', $application->id)->sole();
        $this->assertSame($reason, $audit->reason);
        $this->assertSame($batchOne->id, $audit->metadata['period_before']);
        $this->assertSame($batchTwo->id, $audit->metadata['period_after']);
    }

    public function test_pasted_emails_select_candidates_without_duplicates_and_preserve_previous_selection(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $first = User::factory()->create(['role' => Role::Candidate, 'email' => 'andi@example.com']);
        $second = User::factory()->create(['role' => Role::Candidate, 'email' => 'budi@example.com']);
        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('existingCandidateIds', [(string) $first->id])
            ->call('selectCandidatesByEmail', " ANDI@example.com\r\n\tbudi@example.com;andi@example.com, budi@example.com ")
            ->assertHasNoErrors()->assertSet('existingCandidateIds', [(string) $first->id, (string) $second->id])
            ->assertSet('candidateSearch', '')->assertSee('2 kandidat dari email berhasil dipilih');
    }

    public function test_pasted_invalid_or_ineligible_emails_do_not_change_selection(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $user = User::factory()->create(['role' => Role::Candidate]);
        $inactive = User::factory()->create(['role' => Role::Candidate, 'active' => false]);
        $application = RecruitmentApplication::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('existingCandidateIds', [(string) $user->id]);
        $page->call('selectCandidatesByEmail', '')->assertHasErrors('candidateSearch');
        foreach (['invalid-email', 'missing@example.com', $inactive->email, $admin->email, $application->user->email, str_repeat('x', 30001), implode(' ', array_map(fn ($i) => "candidate$i@example.com", range(1, 101)))] as $text) {
            $page->call('selectCandidatesByEmail', $user->email.' '.$text)
                ->assertHasErrors('candidateSearch')->assertSet('existingCandidateIds', [(string) $user->id]);
        }
        $admin->update(['active' => false]);
        $page->call('selectCandidatesByEmail', $user->email)->assertForbidden();
    }

    public function test_admin_opens_revision_and_candidate_receives_editable_draft(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $submission = Submission::factory()->create();
        $candidate = $submission->application->user;
        $file = UploadedFile::factory()->create(['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $candidate->id]);
        $service = app(SubmissionService::class);
        $draft = $service->draft($submission, $candidate);
        $final = $service->save($submission, $candidate, $draft->id, ['notes' => 'Versi awal', 'links' => [], 'attachments' => [['id' => $file->id, 'description' => '']]], true);

        Livewire::actingAs($admin)->test(Recruitment::class)
            ->call('openApplication', $submission->recruitment_application_id)
            ->call('selectTab', 'Portofolio')->assertSee('Buka revisi')
            ->call('prepareAction', 'revision')->assertSet('action', 'revision')
            ->assertSee('Konfirmasi pembukaan revisi')
            ->call('applyAction')->assertHasErrors('reason')->assertSet('action', 'revision')
            ->set('reason', 'Mohon lengkapi dokumen portofolio')
            ->set('deadline', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('applyAction')->assertHasErrors('deadline')->assertSet('action', 'revision')
            ->set('deadline', now()->addWeek()->format('Y-m-d\TH:i'))
            ->call('applyAction')->assertHasNoErrors()->assertSet('action', '')
            ->assertSee('Revisi berhasil dibuka');

        $this->assertSame('revision', $submission->fresh()->status->value);
        $this->assertSame('final', $final->fresh()->status);
        $revision = $submission->versions()->where('status', 'draft')->sole();
        $this->assertSame($file->id, $revision->attachments()->sole()->uploaded_file_id);
        Livewire::actingAs($candidate)->test(SubmissionForm::class, ['submission' => $submission->fresh()])
            ->assertSet('versionId', $revision->id)->assertSee('HR membuka revisi')
            ->set('notes', 'Versi diperbarui')->call('save')->assertHasNoErrors();
    }

    public function test_admin_can_restore_archived_application_and_keep_its_existing_submission(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $submission = Submission::factory()->create(['deadline' => now()->subDay()]);
        $application = $submission->application;
        $reason = 'Kandidat melanjutkan seleksi';

        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $application->id)
            ->assertDontSee('Aktifkan kembali lamaran')
            ->call('prepareAction', 'archive')->set('reason', 'Pengarsipan sementara')->call('applyAction')
            ->assertSee('Aktifkan kembali lamaran')
            ->call('prepareAction', 'restore')->assertSee('Konfirmasi pengaktifan kembali lamaran')
            ->call('applyAction')->assertHasErrors('reason')
            ->set('reason', $reason)->call('applyAction')->assertHasNoErrors()->assertSee('Lamaran aktif kembali.')
            ->assertDontSee('Aktifkan kembali lamaran');

        $page->call('prepareAction', 'restore')->set('reason', $reason)->call('applyAction')->assertHasNoErrors()
            ->call('navigate', 'applications')->assertViewHas('applications', fn ($applications): bool => $applications->getCollection()->modelKeys() === [$application->id]);
        $this->assertDatabaseHas('recruitment_applications', ['id' => $application->id, 'active_user_id' => $application->user_id, 'archived_at' => null]);
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'recruitment_application_id' => $application->id, 'deadline' => $submission->getRawOriginal('deadline')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.restored', 'actor_id' => $admin->id, 'target_id' => $application->id, 'reason' => $reason]);
        $this->assertSame(1, AuditLog::where('action', 'application.restored')->count());
    }

    public function test_admin_cannot_restore_an_application_when_the_candidate_already_has_an_active_one(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $archived = RecruitmentApplication::factory()->create(['archived_at' => now(), 'active_user_id' => null]);
        $active = RecruitmentApplication::factory()->create(['user_id' => $archived->user_id, 'active_user_id' => $archived->user_id]);

        Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $archived->id)
            ->call('prepareAction', 'restore')->set('reason', 'Kandidat melanjutkan seleksi')->call('applyAction')
            ->assertHasErrors('restore')->assertSee('Kandidat sudah memiliki lamaran aktif.');

        $this->assertDatabaseHas('recruitment_applications', ['id' => $archived->id, 'active_user_id' => null]);
        $this->assertDatabaseHas('recruitment_applications', ['id' => $active->id, 'active_user_id' => $archived->user_id, 'archived_at' => null]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'application.restored']);
    }

    #[TestWith([true, '2026-01-01 00:00:00', 'Data lamaran telah dibersihkan sesuai kebijakan retensi.'], 'retention cleanup')]
    #[TestWith([false, null, 'Lamaran hanya dapat diaktifkan kembali untuk akun kandidat yang aktif.'], 'inactive account')]
    public function test_admin_cannot_restore_an_ineligible_application(bool $active, ?string $purgedAt, string $message): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $archived = RecruitmentApplication::factory()->create(['archived_at' => now(), 'active_user_id' => null, 'purged_at' => $purgedAt]);
        $archived->user->update(['active' => $active]);

        Livewire::actingAs($admin)->test(Recruitment::class)->call('openApplication', $archived->id)
            ->call('prepareAction', 'restore')->set('reason', 'Kandidat melanjutkan seleksi')->call('applyAction')
            ->assertHasErrors('restore')->assertSee($message);

        $this->assertDatabaseHas('recruitment_applications', ['id' => $archived->id, 'active_user_id' => null]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'application.restored']);
    }

    public function test_admin_creates_application_and_changes_deadline(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $p = Position::factory()->create();
        $period = RecruitmentPeriod::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')->set('candidate', ['name' => 'Kandidat Baru', 'email' => 'baru@example.com', 'position_id' => $p->id, 'recruitment_period_id' => $period->id, 'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i')])->call('createCandidate')->assertHasNoErrors()->assertDispatched('access-created');
        $application = RecruitmentApplication::whereHas('user', fn ($q) => $q->where('email', 'baru@example.com'))->firstOrFail();
        $this->assertSame(1, $application->submissions()->count());
        $page->call('selectTab', 'Portofolio')->call('prepareAction', 'deadline')->set('reason', 'Perpanjangan sesuai permintaan')->set('deadline', now()->addWeek()->format('Y-m-d H:i'))->call('applyAction')->assertHasNoErrors();
        $this->assertTrue($application->submissions()->where('type', 'portfolio')->first()->deadline->gt(now()->addDays(3)));
        $page->call('navigate', 'audit')->assertSee('submission.deadline_changed');
    }

    public function test_admin_can_edit_catalog_and_filter_applications(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $position = Position::factory()->create();
        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'positions')->call('editCatalog', $position->id)->set('catalog.name', 'Posisi diperbarui')->call('saveCatalog')->assertHasNoErrors();
        $this->assertSame('Posisi diperbarui', $position->fresh()->name);
    }

    public function test_existing_candidate_selection_uses_stored_identity_without_resetting_password(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $user = User::factory()->create(['name' => 'Kandidat Terpilih', 'role' => Role::Candidate]);
        $password = $user->password;
        $data = ['name' => 'Identitas palsu', 'email' => 'palsu@example.com', 'position_id' => Position::factory()->create()->id,
            'recruitment_period_id' => RecruitmentPeriod::factory()->create()->id,
            'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i'),
        ];

        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('candidateSearch', $user->email)->assertSee('Kandidat Terpilih')
            ->set('existingCandidateIds', [(string) $user->id])->set('candidate', $data)
            ->call('createCandidate')->assertHasNoErrors()->assertNotDispatched('access-created');

        $this->assertDatabaseHas('recruitment_applications', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('users', ['email' => 'palsu@example.com']);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('Kandidat Terpilih', $user->fresh()->name);

        Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('existingCandidateIds', [(string) $user->id])
            ->set('candidate', $data)->call('createCandidate')->assertHasErrors('existingCandidateIds');
        $this->assertSame(1, $user->applications()->count());
    }

    public function test_existing_candidate_rejects_missing_inactive_admin_and_deleted_accounts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $inactive = User::factory()->create(['role' => Role::Candidate, 'active' => false]);
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->call('createCandidate')->assertHasErrors('existingCandidateIds');
        foreach ([$admin->id, $inactive->id, 999999] as $id) {
            $page->set('existingCandidateIds', [(string) $id])->call('createCandidate')->assertHasErrors('existingCandidateIds');
        }
        $this->assertDatabaseCount('recruitment_applications', 0);
    }

    public function test_multiple_candidates_keep_selection_during_search_and_receive_same_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $users = User::factory()->count(2)->create(['role' => Role::Candidate]);
        $ids = $users->pluck('id')->map(fn ($id) => (string) $id)->all();
        $data = ['position_id' => Position::factory()->create()->id, 'recruitment_period_id' => RecruitmentPeriod::factory()->create()->id,
            'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i')];
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('existingCandidateIds', $ids)
            ->set('candidateSearch', 'tidak-ada-hasil')->assertSet('existingCandidateIds', $ids)
            ->set('candidate', $data)->call('createCandidate')->assertHasNoErrors()
            ->assertSet('section', 'applications')->assertSet('existingCandidateIds', [])
            ->assertSee('2 lamaran berhasil dibuat')->assertNotDispatched('access-created');
        foreach ($users as $user) {
            $application = $user->applications()->sole();
            $this->assertEquals($data['position_id'], $application->position_id);
            $this->assertEquals($data['recruitment_period_id'], $application->recruitment_period_id);
            $this->assertSame(1, $application->submissions()->count());
            $this->assertFalse($application->submissions()->where('type', 'technical_test')->exists());
        }
    }

    public function test_bulk_creation_rejects_duplicates_and_rolls_back_on_later_failure(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $users = User::factory()->count(2)->create(['role' => Role::Candidate]);
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')
            ->set('candidateMode', 'existing')->set('existingCandidateIds', [$users[0]->id, $users[0]->id])
            ->call('createCandidate')->assertHasErrors('existingCandidateIds.0');
        $data = ['position_id' => Position::factory()->create()->id, 'recruitment_period_id' => RecruitmentPeriod::factory()->create()->id,
            'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i'), 'test_deadline' => now()->addDays(4)->format('Y-m-d H:i'),
            'task_label' => 'Tes bersama'];
        $attempts = 0;
        RecruitmentApplication::creating(function () use (&$attempts) {
            if (++$attempts === 2) {
                throw ValidationException::withMessages(['existingCandidateIds' => 'Simulasi kegagalan kandidat kedua.']);
            }
        });
        try {
            $page->set('existingCandidateIds', $users->pluck('id')->all())->set('candidate', $data)
                ->call('createCandidate')->assertHasErrors('existingCandidateIds');
            $this->assertSame(2, $attempts);
            $this->assertDatabaseCount('recruitment_applications', 0);
            $this->assertDatabaseCount('submissions', 0);
        } finally {
            RecruitmentApplication::flushEventListeners();
        }
    }
}
