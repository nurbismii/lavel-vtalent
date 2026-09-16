<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Livewire\Candidate\SubmissionForm;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_creates_application_and_changes_deadline(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        $p = Position::factory()->create();
        $period = RecruitmentPeriod::factory()->create();
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'create')->set('candidate', ['name' => 'Kandidat Baru', 'email' => 'baru@example.com', 'position_id' => $p->id, 'recruitment_period_id' => $period->id, 'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i'), 'test_deadline' => now()->addDays(4)->format('Y-m-d H:i'), 'task_label' => 'Tes web', 'instructions' => 'Unggah hasil'])->call('createCandidate')->assertHasNoErrors()->assertDispatched('access-created');
        $application = RecruitmentApplication::whereHas('user', fn ($q) => $q->where('email', 'baru@example.com'))->firstOrFail();
        $this->assertSame(2, $application->submissions()->count());
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
            'test_deadline' => now()->addDays(4)->format('Y-m-d H:i'), 'task_label' => 'Tes web', 'instructions' => ''];

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
            'portfolio_deadline' => now()->addDays(3)->format('Y-m-d H:i'), 'test_deadline' => now()->addDays(4)->format('Y-m-d H:i'),
            'task_label' => 'Tes bersama', 'instructions' => 'Petunjuk bersama'];
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
            $this->assertSame(2, $application->submissions()->count());
            $this->assertSame('Tes bersama', $application->submissions()->where('type', 'technical_test')->sole()->task_label);
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
