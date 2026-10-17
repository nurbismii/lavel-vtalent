<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\CandidateForms;
use App\Jobs\LinkFormCandidateAccount;
use App\Jobs\ScanFormDocument;
use App\Jobs\SendCandidateAccess;
use App\Jobs\SendFormAccess;
use App\Models\AccessDelivery;
use App\Models\AppSetting;
use App\Models\CandidateForm;
use App\Models\FormAccessToken;
use App\Models\FormAccountDispatch;
use App\Models\FormIntake;
use App\Models\FormResponse;
use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\Submission;
use App\Models\User;
use App\Services\CandidateFormService;
use App\Services\FormAccountBulkService;
use App\Services\FormDocumentService;
use App\Services\FormResponseExportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CandidateFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_optional_response_limit_is_stored_as_null(): void
    {
        [$admin,, $existing] = $this->setupForm();
        foreach (['', '   ', null] as $limit) {
            Livewire::actingAs($admin)->test(CandidateForms::class)
                ->set('intake.candidate_form_version_id', $existing->candidate_form_version_id)
                ->set('intake.position_id', $existing->position_id)
                ->set('intake.recruitment_period_id', $existing->recruitment_period_id)
                ->set('intake.max_responses', $limit)
                ->call('createIntake')->assertHasNoErrors();
            $intake = FormIntake::latest('id')->firstOrFail();
            $this->assertNotSame($existing->id, $intake->id);
            $this->assertNull($intake->getRawOriginal('max_responses'));
        }
    }

    public function test_intake_response_limit_preserves_numbers_and_rejects_invalid_values(): void
    {
        [$admin,, $existing] = $this->setupForm();
        $data = $existing->only('candidate_form_version_id', 'position_id', 'recruitment_period_id');
        $service = app(CandidateFormService::class);
        $intake = $service->createIntake($admin, [...$data, 'max_responses' => '25']);
        $this->assertSame(25, $intake->fresh()->getRawOriginal('max_responses'));
        $count = FormIntake::count();
        foreach (['0', '-1', 'abc', '100001'] as $invalid) {
            try {
                $service->createIntake($admin, [...$data, 'max_responses' => $invalid]);
                $this->fail('Batas respons tidak valid harus ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('max_responses', $exception->errors());
            }
        }
        $this->assertSame($count, FormIntake::count());
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    private function field(string $type = 'text', bool $required = true): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'label' => 'Pertanyaan '.$type, 'help' => '', 'placeholder' => '', 'required' => $required, 'options' => ['Satu', 'Dua'], 'max_length' => 255, 'min' => '', 'max' => '', 'max_mb' => 10, 'max_files' => 2, 'extensions' => ['pdf']];
    }

    private function setupForm(array $fields = []): array
    {
        $actor = $this->admin();
        $service = app(CandidateFormService::class);
        $template = $service->saveTemplate($actor, null, 0, ['title' => 'Data lengkap kandidat', 'description' => 'Lengkapi informasi Anda', 'fields' => $fields]);
        $version = $service->publish($actor, $template, $template->lock_version);
        $intake = $service->createIntake($actor, ['candidate_form_version_id' => $version->id, 'position_id' => Position::factory()->create()->id, 'recruitment_period_id' => RecruitmentPeriod::factory()->create()->id, 'deadline' => now()->addDays(5)->toDateTimeString()]);

        return [$actor, $template, $intake];
    }

    private function response($intake, string $email = 'candidate@example.com'): FormResponse
    {
        return $intake->responses()->create(['reference' => (string) Str::uuid(), 'name' => 'Nama dari formulir', 'email' => $email, 'email_verified_at' => now(), 'answers' => []]);
    }

    private function grant(FormResponse $response): static
    {
        return $this->withSession(['form_access.'.$response->id => ['generation' => $response->access_generation, 'expires' => now()->addHour()->timestamp]]);
    }

    private function finalized(FormResponse $response, array $answers = []): FormResponse
    {
        return app(CandidateFormService::class)->save($response, $response->lock_version, ['name' => $response->name, 'answers' => $answers, 'consent' => true], true);
    }

    private function applicationData(): array
    {
        return ['portfolio_deadline' => now()->addDays(7)->toDateTimeString()];
    }

    public function test_duplicate_verified_email_and_repeated_submit_notify_without_changing_final_answers(): void
    {
        $field = $this->field();
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->finalized($this->response($intake), [$field['id'] => 'Jawaban asli']);
        $before = $response->getRawOriginal();
        $this->grant($response);
        $notice = 'Formulir dengan email ini sudah dikirim. Pengiriman kedua tidak dibuat. Anda dapat melihat jawaban sebelumnya.';

        $this->post(route('forms.access', $intake->slug), ['email' => strtoupper($response->email), 'name' => 'Nama pengganti', 'answers' => [$field['id'] => 'Pengganti']])
            ->assertRedirect(route('forms.response', $response->reference))->assertSessionHas('status', $notice);
        $this->post(route('forms.submit', $response->reference), ['lock_version' => 0, 'consent' => 1])
            ->assertRedirect(route('forms.response', $response->reference))->assertSessionHas('status', $notice);
        $this->get(route('forms.response', $response->reference))->assertSee('Pengiriman kedua dengan email yang sama tidak dibuat')->assertSee('Jawaban asli')->assertDontSee('Nama pengganti');

        $this->assertSame($before, $response->fresh()->getRawOriginal());
        $this->assertDatabaseCount('form_responses', 1);
        $this->assertDatabaseCount('form_response_revisions', 1);
        $this->assertDatabaseCount('form_access_tokens', 0);
    }

    public function test_duplicate_email_on_another_session_requires_verification_before_showing_submission_status(): void
    {
        Queue::fake([SendFormAccess::class]);
        $field = $this->field();
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->finalized($this->response($intake), [$field['id'] => 'Jawaban privat']);

        $this->post(route('forms.access', $intake->slug), ['email' => strtoupper($response->email)])
            ->assertRedirect(route('forms.waiting', $intake->slug));
        $this->get(route('forms.waiting', $intake->slug))->assertSee('Satu email hanya dapat mengirim satu respons')->assertDontSee('Jawaban privat')->assertDontSee($response->reference);
        $this->get(route('forms.response', $response->reference))->assertNotFound();
        $job = Queue::pushed(SendFormAccess::class)->sole();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect(route('forms.response', $response->reference));
        $this->get(route('forms.response', $response->reference))->assertSee('Pengiriman kedua dengan email yang sama tidak dibuat')->assertSee('Jawaban privat');

        Queue::assertPushed(SendFormAccess::class, 1);
        $this->assertDatabaseCount('form_responses', 1);
        $this->assertDatabaseCount('form_response_revisions', 1);
    }

    private function conditionalFields(): array
    {
        $choice = [...$this->field('radio'), 'label' => 'Memiliki pengalaman kerja?', 'options' => ['Ya', 'Tidak']];
        $section = [...$this->field('section', false), 'label' => 'Pengalaman kerja', 'condition' => ['field_id' => $choice['id'], 'value' => 'Ya']];

        return [$choice, $section, [...$this->field(), 'label' => 'Nama perusahaan'], [...$this->field('file'), 'label' => 'Surat pengalaman'], $this->field('section', false), [...$this->field(), 'label' => 'Domisili']];
    }

    public function test_editor_publishes_unique_fields_and_preserves_existing_intake_rules(): void
    {
        $field = $this->field('numeric_text');
        [$admin, $template, $intake] = $this->setupForm([$field]);
        $template->update(['fields' => [$field]]);
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('edit', $template->id)
            ->assertSet('fields.0.unique', false)->assertSee('Jawaban harus unik')
            ->set('fields.0.unique', true)->call('publish')->assertHasNoErrors();

        $this->assertTrue($template->fresh()->fields[0]['unique']);
        $this->assertTrue($template->versions()->latest('number')->firstOrFail()->fields[0]['unique']);
        $this->assertFalse($intake->version->fields[0]['unique']);
        $page->call('duplicate', $template->id)->assertHasNoErrors()->assertSet('fields.0.unique', true)
            ->set('fields.0.type', 'file')->assertSet('fields.0.unique', false);
    }

    #[TestWith(['file'])]
    #[TestWith(['section'])]
    public function test_unique_option_rejects_fields_without_answers(string $type): void
    {
        $this->expectException(ValidationException::class);

        app(CandidateFormService::class)->schema(['title' => 'Form', 'fields' => [[...$this->field($type), 'unique' => true]]]);
    }

    #[TestWith(['numeric_text', '0012345678901234', '0012345678901234'])]
    #[TestWith(['email', 'Person@Example.com', 'person@example.com'])]
    #[TestWith(['text', '  Kandidat  ', 'kandidat'])]
    #[TestWith(['text', "O'Reilly", "O'Reilly"])]
    #[TestWith(['number', '01.50', '1.5'])]
    #[TestWith(['number', '0', '0.0'])]
    #[TestWith(['checkbox', ['Satu', 'Dua'], ['Dua', 'Satu']])]
    #[TestWith(['consent', true, '1'])]
    #[TestWith(['consent', false, '0'])]
    public function test_unique_field_rejects_duplicate_draft_answers(string $type, mixed $firstValue, mixed $duplicate): void
    {
        $field = [...$this->field($type, false), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $first = $this->response($intake, 'first@example.com');
        app(CandidateFormService::class)->save($first, 0, ['answers' => [$field['id'] => $firstValue]]);
        $second = $this->response($intake, 'second@example.com');

        $this->grant($second)->post(route('forms.save', $second->reference), ['lock_version' => 0, 'answers' => [$field['id'] => $duplicate], 'action' => 'save'])
            ->assertSessionHasErrors(['answers.'.$field['id'] => $field['label'].' sudah digunakan pada penerimaan ini. Gunakan nilai lain.']);

        $this->assertSame([], $second->fresh()->answers);
        $this->assertSame(0, $second->fresh()->lock_version);
        $this->assertDatabaseCount('form_response_revisions', 0);
    }

    public function test_unique_field_blocks_access_request_before_sending_email(): void
    {
        Queue::fake([SendFormAccess::class]);
        $field = [...$this->field('numeric_text'), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $this->finalized($this->response($intake), [$field['id'] => '0012345678901234']);

        $this->post(route('forms.access', $intake->slug), ['email' => 'other@example.com', 'answers' => [$field['id'] => '0012345678901234']])
            ->assertSessionHasErrors('answers.'.$field['id']);

        $this->assertDatabaseCount('form_access_tokens', 0);
        $this->assertDatabaseCount('form_responses', 1);
        Queue::assertNothingPushed();
    }

    public function test_unique_conflict_during_email_verification_returns_to_editable_form_with_input(): void
    {
        Queue::fake([SendFormAccess::class]);
        $field = [...$this->field('numeric_text'), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $this->post(route('forms.access', $intake->slug), ['email' => 'pending@example.com', 'name' => 'Kandidat', 'answers' => [$field['id'] => '0012345678901234']])->assertSessionHasNoErrors();
        $job = Queue::pushed(SendFormAccess::class)->sole();
        $this->finalized($this->response($intake), [$field['id'] => '0012345678901234']);

        $result = $this->post(route('forms.consume', $job->rawToken));

        $response = FormResponse::where('email', 'pending@example.com')->sole();
        $result->assertRedirect(route('forms.response', $response->reference))->assertSessionHasErrors('answers.'.$field['id'])
            ->assertSessionHasInput('answers.'.$field['id'], '0012345678901234');
        $this->assertSame([], $response->answers);
        $this->get(route('forms.response', $response->reference))->assertOk()->assertSee('0012345678901234');
        Queue::assertPushed(SendFormAccess::class, 1);
    }

    public function test_unique_field_is_rechecked_on_final_submission(): void
    {
        $field = [...$this->field('numeric_text'), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $response->update(['answers' => [$field['id'] => '0012345678901234']]);
        $this->finalized($this->response($intake, 'other@example.com'), [$field['id'] => '1123456789012345']);
        $response->update(['answers' => [$field['id'] => '1123456789012345']]);

        $this->grant($response)->withSession(['form_review.'.$response->id => 0])->post(route('forms.submit', $response->reference), ['lock_version' => 0, 'consent' => true])
            ->assertSessionHasErrors('answers.'.$field['id']);

        $this->assertNull($response->fresh()->submitted_at);
        $this->assertSame('draft', $response->fresh()->status);
        $this->assertSame(0, $response->revisions()->count());
    }

    public function test_unique_field_allows_own_draft_submission_and_revision(): void
    {
        $field = [...$this->field('numeric_text'), 'unique' => true];
        [$admin,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $answers = [$field['id'] => '0012345678901234'];

        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => $answers, 'consent' => true, 'action' => 'review'])
            ->assertOk()->assertSessionHasNoErrors();
        $this->post(route('forms.submit', $response->reference), ['lock_version' => 1, 'consent' => true])->assertSessionHasNoErrors();
        app(CandidateFormService::class)->revise($admin, $response->fresh(), 'Perbaiki jawaban lainnya', now()->addDay()->toDateTimeString());
        $revised = $this->finalized($response->fresh(), $answers);

        $this->assertSame($answers, $revised->answers);
        $this->assertSame('submitted', $revised->status);
        $this->assertSame(2, $revised->revisions()->count());
    }

    public function test_unique_field_allows_same_value_in_another_intake(): void
    {
        $field = [...$this->field('numeric_text'), 'unique' => true];
        [$admin,, $intake] = $this->setupForm([$field]);
        $answers = [$field['id'] => '0012345678901234'];
        $this->finalized($this->response($intake), $answers);
        $other = app(CandidateFormService::class)->createIntake($admin, $intake->only('candidate_form_version_id', 'position_id', 'recruitment_period_id'));

        $response = $this->finalized($this->response($other), $answers);

        $this->assertSame('submitted', $response->status);
        $this->assertSame($answers, $response->answers);
    }

    public function test_unchecked_unique_option_allows_duplicate_values(): void
    {
        $field = [...$this->field('numeric_text'), 'unique' => false];
        [,, $intake] = $this->setupForm([$field]);
        $answers = [$field['id'] => '0012345678901234'];
        $this->finalized($this->response($intake), $answers);

        $response = $this->finalized($this->response($intake, 'other@example.com'), $answers);

        $this->assertSame('submitted', $response->status);
        $this->assertSame($answers, $response->answers);
    }

    #[TestWith(['numeric_text', '00123', '123'])]
    #[TestWith(['checkbox', ['Satu', 'Dua'], ['Satu']])]
    public function test_unique_field_allows_distinct_values(string $type, mixed $firstValue, mixed $secondValue): void
    {
        $field = [...$this->field($type), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $this->finalized($this->response($intake), [$field['id'] => $firstValue]);

        $response = $this->finalized($this->response($intake, 'other@example.com'), [$field['id'] => $secondValue]);

        $this->assertSame('submitted', $response->status);
        $this->assertSame([$field['id'] => $secondValue], $response->answers);
    }

    #[TestWith(['text', ''])]
    #[TestWith(['checkbox', []])]
    public function test_optional_unique_fields_allow_multiple_empty_answers(string $type, mixed $value): void
    {
        $field = [...$this->field($type, false), 'unique' => true];
        [,, $intake] = $this->setupForm([$field]);
        $answers = [$field['id'] => $value];
        $this->finalized($this->response($intake), $answers);

        $response = $this->finalized($this->response($intake, 'other@example.com'), $answers);

        $this->assertSame('submitted', $response->status);
    }

    public function test_hidden_unique_answers_are_ignored(): void
    {
        $choice = [...$this->field('radio'), 'options' => ['Ya', 'Tidak']];
        $field = [...$this->field('numeric_text'), 'unique' => true, 'condition' => ['field_id' => $choice['id'], 'value' => 'Ya']];
        [,, $intake] = $this->setupForm([$choice, $field]);
        $this->finalized($this->response($intake), [$choice['id'] => 'Ya', $field['id'] => '0012345678901234']);

        $response = $this->finalized($this->response($intake, 'other@example.com'), [$choice['id'] => 'Tidak', $field['id'] => '0012345678901234']);

        $this->assertSame([$choice['id'] => 'Tidak'], $response->answers);
        $this->assertSame('submitted', $response->status);
    }

    public function test_conditional_section_ignores_hidden_answers_and_required_documents_in_review_and_submission(): void
    {
        [$choice,, $company, $file,, $city] = $fields = $this->conditionalFields();
        [,, $intake] = $this->setupForm($fields);
        $response = $this->response($intake);
        $document = $response->documents()->create(['field_id' => $file['id'], 'path' => 'private/experience.pdf', 'original_name' => 'experience.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'pending']);
        $response->update(['answers' => [$choice['id'] => 'Ya', $company['id'] => 'Perusahaan lama']]);
        $answers = [$choice['id'] => 'Tidak', $company['id'] => ['invalid hidden input'], $city['id'] => 'Makassar'];

        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => $response->lock_version, 'name' => $response->name, 'answers' => $answers, 'consent' => true, 'action' => 'review'])
            ->assertOk()->assertSessionHasNoErrors()->assertSee('Periksa sebelum dikirim')->assertDontSee('Nama perusahaan')->assertDontSee('Surat pengalaman')->assertSee('Makassar');
        $response->refresh();
        $this->assertSame([$choice['id'] => 'Tidak', $city['id'] => 'Makassar'], $response->answers);
        $this->post(route('forms.submit', $response->reference), ['lock_version' => $response->lock_version, 'consent' => true])->assertRedirect();
        $response->refresh();
        $this->assertSame('submitted', $response->status);
        $this->assertSame([], $response->latestRevision->document_ids);
        $this->assertTrue($document->fresh()->selected);
    }

    public function test_conditional_section_requires_visible_answers_and_documents(): void
    {
        [$choice,, $company, $file,, $city] = $fields = $this->conditionalFields();
        [,, $intake] = $this->setupForm($fields);
        $response = $this->response($intake);
        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => $response->lock_version, 'name' => $response->name, 'answers' => [$choice['id'] => 'Ya', $city['id'] => 'Makassar'], 'consent' => true, 'action' => 'review'])
            ->assertSessionHasErrors('answers.'.$company['id']);
        $this->post(route('forms.save', $response->reference), ['lock_version' => $response->lock_version, 'name' => $response->name, 'answers' => [$choice['id'] => 'Ya', $company['id'] => 'PT Contoh', $city['id'] => 'Makassar'], 'consent' => true, 'action' => 'review'])
            ->assertSessionHasErrors('files.'.$file['id']);
        $this->assertSame([], $response->fresh()->answers);
        $this->assertNull($response->fresh()->submitted_at);
    }

    #[TestWith(['radio', 'Ya'])]
    #[TestWith(['select', 'Ya'])]
    #[TestWith(['checkbox', ['Ya']])]
    public function test_conditional_fields_support_choice_types_and_ignore_hidden_trigger_answers(string $type, string|array $answer): void
    {
        $choice = [...$this->field($type), 'options' => ['Ya', 'Tidak']];
        $followup = [...$this->field('select', false), 'condition' => ['field_id' => $choice['id'], 'value' => 'Ya']];
        $detail = [...$this->field(), 'condition' => ['field_id' => $followup['id'], 'value' => 'Satu']];
        $service = app(CandidateFormService::class);
        $fields = [$choice, $followup, $detail];
        $valid = $service->validateAnswers($fields, ['name' => 'Kandidat', 'consent' => true, 'answers' => [$choice['id'] => $answer, $followup['id'] => 'Satu', $detail['id'] => 'Detail']], true);
        $this->assertSame('Detail', $valid['answers'][$detail['id']]);
        $hidden = $service->validateAnswers($fields, ['name' => 'Kandidat', 'consent' => true, 'answers' => [$choice['id'] => $type === 'checkbox' ? ['Tidak'] : 'Tidak', $followup['id'] => 'Satu', $detail['id'] => 'Stale']], true);
        $this->assertArrayNotHasKey($followup['id'], $hidden['answers']);
        $this->assertArrayNotHasKey($detail['id'], $hidden['answers']);
    }

    #[TestWith(['missing'])]
    #[TestWith(['self'])]
    #[TestWith(['forward'])]
    #[TestWith(['nonchoice'])]
    #[TestWith(['option'])]
    public function test_form_schema_rejects_invalid_conditional_rules(string $case): void
    {
        $source = $this->field($case === 'nonchoice' ? 'text' : 'radio');
        $dependent = [...$this->field(), 'condition' => ['field_id' => $source['id'], 'value' => 'Satu']];
        if ($case === 'missing') {
            $dependent['condition']['field_id'] = (string) Str::uuid();
        } elseif ($case === 'self') {
            $dependent['condition']['field_id'] = $dependent['id'];
        } elseif ($case === 'option') {
            $dependent['condition']['value'] = 'Tidak tersedia';
        }
        $fields = $case === 'forward' ? [$dependent, $source] : [$source, $dependent];
        $this->expectException(ValidationException::class);
        app(CandidateFormService::class)->schema(['title' => 'Form bersyarat', 'fields' => $fields]);
    }

    public function test_duplicate_form_remaps_conditional_sources_and_blocks_deleting_a_used_trigger(): void
    {
        $fields = $this->conditionalFields();
        [$admin, $template] = $this->setupForm($fields);
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('duplicate', $template->id)->assertHasNoErrors();
        $copy = CandidateForm::latest('id')->firstOrFail();
        $this->assertNotSame($fields[0]['id'], $copy->fields[0]['id']);
        $this->assertSame($copy->fields[0]['id'], $copy->fields[1]['condition']['field_id']);
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('edit', $copy->id)->call('removeField', 0)->assertHasErrors('fields')->assertCount('fields', 6);
    }

    public function test_editor_saves_and_previews_conditional_rules_and_resets_the_value_when_source_changes(): void
    {
        $choice = [...$this->field('radio'), 'options' => ['Ya', 'Tidak']];
        $other = $this->field('select');
        $detail = $this->field();
        [$admin, $template] = $this->setupForm([$choice, $other, $detail]);
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('edit', $template->id)
            ->set('fields.2.condition.field_id', $choice['id'])->set('fields.2.condition.value', 'Ya')
            ->call('save')->assertHasNoErrors()->call('showPreview')->assertHasNoErrors()->assertSet('preview', true)->assertSee('candidate-form-fields', false);
        $this->assertSame(['field_id' => $choice['id'], 'value' => 'Ya'], $template->fresh()->fields[2]['condition']);
        $page->set('fields.2.condition.field_id', $other['id'])->assertSet('fields.2.condition.value', '')->assertSet('preview', false);
    }

    public function test_upload_rejects_a_hidden_conditional_document(): void
    {
        [$choice,,, $file] = $fields = $this->conditionalFields();
        [,, $intake] = $this->setupForm($fields);
        $response = $this->response($intake);
        $response->update(['answers' => [$choice['id'] => 'Tidak']]);
        $this->grant($response)->post(route('forms.upload', $response->reference), ['field_id' => $file['id'], 'upload' => UploadedFile::fake()->create('experience.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->assertDatabaseCount('form_documents', 0);
    }

    public function test_public_form_renders_without_login_and_creates_no_account(): void
    {
        [,, $intake] = $this->setupForm([$this->field('date'), $this->field('file')]);
        $this->get(route('forms.show', $intake->slug))->assertOk()->assertSee('Kirim tautan verifikasi email')->assertSee('Pertanyaan date')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('form_responses', 0);
    }

    public function test_email_access_can_be_reused_keeps_draft_and_never_creates_an_account(): void
    {
        Queue::fake([SendFormAccess::class]);
        $this->freezeSecond();
        $field = $this->field();
        [,, $intake] = $this->setupForm([$field]);
        $this->post(route('forms.access', $intake->slug), ['email' => 'Person@Example.com', 'name' => 'Nama Awal', 'answers' => [$field['id'] => 'Draf awal']])->assertSessionHasNoErrors();
        $job = Queue::pushed(SendFormAccess::class)->first();
        $this->assertNotNull($job);
        $this->assertDatabaseHas('form_access_tokens', ['token_hash' => hash('sha256', $job->rawToken), 'email' => 'person@example.com']);
        $this->get(route('forms.verify', $job->rawToken))->assertOk();
        $this->assertDatabaseCount('form_responses', 0);
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        $response = FormResponse::firstOrFail();
        $this->assertSame('Draf awal', $response->answers[$field['id']]);
        $this->get(route('forms.response', $response->reference))->assertOk()->assertSee('Draf awal');
        $token = FormAccessToken::sole();
        $verifiedAt = $token->used_at;
        $this->app['session']->flush();
        $this->travel(1)->day();
        $this->get(route('forms.verify', $job->rawToken))->assertSee('Buka formulir kandidat');
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect(route('forms.response', $response->reference));
        $this->get(route('forms.response', $response->reference))->assertSee('Draf awal');
        $this->assertTrue($token->fresh()->used_at->equalTo($verifiedAt));
        $this->assertDatabaseCount('form_responses', 1);
        Queue::assertPushed(SendFormAccess::class, 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_reopened_email_access_shows_final_answers_but_rejects_all_edits(): void
    {
        Queue::fake([SendFormAccess::class, ScanFormDocument::class]);
        Storage::fake('private');
        $this->freezeSecond();
        $field = $this->field();
        $fileField = $this->field('file', false);
        [,, $intake] = $this->setupForm([$field, $fileField]);
        $this->post(route('forms.access', $intake->slug), ['email' => 'readonly@example.com', 'name' => 'Kandidat Final', 'answers' => [$field['id'] => 'Jawaban final saya']])->assertRedirect();
        $job = Queue::pushed(SendFormAccess::class)->sole();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        $response = FormResponse::sole();
        $document = $response->documents()->create(['field_id' => $fileField['id'], 'path' => 'candidate-forms/final.pdf', 'original_name' => 'final.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        Storage::disk('private')->put($document->path, '%PDF-1.4');
        $response = $this->finalized($response, [$field['id'] => 'Jawaban final saya']);
        $before = $response->getRawOriginal();
        $this->app['session']->flush();
        $this->travel(2)->days();
        $intake->update(['active' => false]);

        $this->get(route('forms.verify', $job->rawToken))->assertOk();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect(route('forms.response', $response->reference))
            ->assertSessionHas('status', 'Respons sudah dikirim. Anda dapat melihat jawaban dan dokumen tanpa mengubahnya.');
        $this->get(route('forms.response', $response->reference))->assertSee('Jawaban final saya')->assertSee('final.pdf')
            ->assertDontSee('Simpan draf')->assertDontSee('candidate-answer-form')->assertDontSee('data-upload-form');
        $this->get(route('forms.document.download', $document))->assertOk();
        $this->post(route('forms.save', $response->reference), ['lock_version' => $response->lock_version, 'name' => 'Diubah', 'action' => 'save'])->assertStatus(422);
        $this->post(route('forms.upload', $response->reference), ['field_id' => $fileField['id'], 'upload' => UploadedFile::fake()->create('new.pdf', 5, 'application/pdf')])->assertStatus(422);
        $this->post(route('forms.document.action', $document), ['action' => 'remove'])->assertStatus(422);
        $this->post(route('forms.document.action', $document), ['action' => 'retry'])->assertStatus(422);
        $this->post(route('forms.submit', $response->reference), ['lock_version' => $response->lock_version, 'consent' => 1])->assertRedirect();

        $this->assertSame($before, $response->fresh()->getRawOriginal());
        $this->assertDatabaseCount('form_response_revisions', 1);
        $this->assertTrue($document->fresh()->selected);
        Storage::disk('private')->assertExists($document->path);
        Queue::assertPushed(SendFormAccess::class, 1);
        Queue::assertNotPushed(ScanFormDocument::class);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_email_link_expires_after_thirty_days_without_being_extended_by_reuse(bool $previouslyUsed): void
    {
        Queue::fake([SendFormAccess::class]);
        $this->freezeSecond();
        [,, $intake] = $this->setupForm();
        $this->post(route('forms.access', $intake->slug), ['email' => 'expires@example.com'])->assertRedirect();
        $job = Queue::pushed(SendFormAccess::class)->sole();
        $token = FormAccessToken::sole();
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(30)));
        if ($previouslyUsed) {
            $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        }
        $this->travel(30)->days();

        $this->get(route('forms.verify', $job->rawToken))->assertStatus(410);
        $this->post(route('forms.consume', $job->rawToken))->assertStatus(410);

        $this->assertTrue($token->fresh()->expires_at->equalTo($token->expires_at));
        $this->assertDatabaseCount('form_responses', $previouslyUsed ? 1 : 0);
    }

    public function test_reused_email_link_redirects_to_login_after_response_is_linked_to_account(): void
    {
        Queue::fake([SendFormAccess::class]);
        [$admin,, $intake] = $this->setupForm();
        $this->post(route('forms.access', $intake->slug), ['email' => 'linked@example.com', 'name' => 'Kandidat'])->assertRedirect();
        $job = Queue::pushed(SendFormAccess::class)->sole();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        $response = $this->finalized(FormResponse::sole());
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $this->app['session']->flush();

        $this->get(route('forms.verify', $job->rawToken))->assertOk();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect(route('login'));
        $this->get(route('forms.response', $response->reference))->assertNotFound();
    }

    public function test_verified_public_form_can_be_completed_in_browser_logged_in_as_admin(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version]);
        $this->post(route('forms.access', $intake->slug), ['email' => 'public.candidate@example.com', 'name' => 'Kandidat Publik'])->assertSessionHasNoErrors();
        $job = Queue::pushed(SendFormAccess::class)->first();
        $redirect = $this->post(route('forms.consume', $job->rawToken));
        $response = FormResponse::where('email', 'public.candidate@example.com')->firstOrFail();
        $redirect->assertRedirect(route('forms.response', $response->reference));
        $this->get($redirect->headers->get('Location'))->assertOk()->assertSee('Simpan draf');
        $this->post(route('forms.save', $response->reference), ['lock_version' => $response->fresh()->lock_version, 'name' => 'Kandidat Publik', 'answers' => [], 'consent' => 1, 'action' => 'review'])->assertOk();
        $this->post(route('forms.submit', $response->reference), ['lock_version' => $response->fresh()->lock_version, 'consent' => 1])->assertRedirect();
        $this->get(route('forms.response', $response->reference))->assertOk()->assertSee('Terima kasih');
        $this->assertNull($response->fresh()->user_id);
        $other = $this->response($intake, 'private.other@example.com');
        $this->get(route('forms.response', $other->reference))->assertNotFound();
    }

    public function test_public_access_generation_is_consistent_for_database_numeric_strings(): void
    {
        [,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $response->setRawAttributes([...$response->getAttributes(), 'access_generation' => '1', 'lock_version' => '0']);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('form_access.'.$response->id, ['generation' => 1, 'expires' => now()->addHour()->timestamp]);
        $service = app(CandidateFormService::class);
        $this->assertTrue($service->hasPublicAccess($request, $response));
        $this->assertSame(0, $response->lock_version);
        $response->setRawAttributes([...$response->getAttributes(), 'access_generation' => '2']);
        $this->assertFalse($service->hasPublicAccess($request, $response));
    }

    public function test_expired_access_and_honeypot_are_rejected(): void
    {
        Queue::fake();
        [,, $intake] = $this->setupForm();
        $this->post(route('forms.access', $intake->slug), ['email' => 'bot@example.com', 'website' => 'spam'])->assertSessionHasErrors('website');
        Queue::assertNothingPushed();
        $raw = Str::random(64);
        FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => 'old@example.com', 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->subMinute()]);
        $this->post(route('forms.consume', $raw))->assertStatus(410);
    }

    public function test_private_response_and_file_require_ownership_even_when_url_is_known(): void
    {
        Storage::fake('private');
        [,, $intake] = $this->setupForm([$this->field('file')]);
        $response = $this->response($intake);
        $file = $response->documents()->create(['field_id' => $intake->version->fields[0]['id'], 'path' => 'candidate-forms/private.pdf', 'original_name' => 'private.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        $this->get(route('forms.response', $response->reference))->assertNotFound();
        $this->get(route('forms.document.download', $file))->assertNotFound();
        $this->post(route('forms.upload', $response->reference), ['field_id' => $file->field_id, 'upload' => UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf')])->assertNotFound();
        $this->grant($response)->get(route('forms.document.download', $file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_document_preview_is_private_inline_and_requires_clean_scan(): void
    {
        Storage::fake('private');
        [,, $intake] = $this->setupForm([$this->field('file')]);
        $response = $this->response($intake);
        $file = $response->documents()->create(['field_id' => $intake->version->fields[0]['id'], 'path' => 'private/cv.pdf', 'original_name' => 'CV.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        $url = route('forms.document.preview', $file);

        $this->get($url)->assertNotFound();
        $other = $this->response($intake, 'other@example.com');
        $this->grant($other)->get($url)->assertNotFound();
        $this->grant($response)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=CV.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $file->update(['scan_status' => 'pending']);
        $this->get($url)->assertNotFound();
    }

    public function test_hr_preview_only_exposes_documents_in_submitted_revisions(): void
    {
        Storage::fake('private');
        [$admin,, $intake] = $this->setupForm([$this->field('file', false)]);
        $response = $this->finalized($this->response($intake));
        $file = $response->documents()->create(['field_id' => $intake->version->fields[0]['id'], 'path' => 'private/hr.pdf', 'original_name' => 'HR.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        Storage::disk('private')->put($file->path, '%PDF-1.4');
        $url = route('forms.document.preview', $file);

        $this->actingAs($admin)->withSession(['portal_session_version' => $admin->session_version])->get($url)->assertNotFound();
        $response->latestRevision->update(['document_ids' => [$file->id]]);
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'inline; filename=HR.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.document_previewed', 'target_id' => $file->id, 'actor_id' => $admin->id]);
    }

    public function test_unsupported_preview_shows_download_option_without_rendering_file_content(): void
    {
        Storage::fake('private');
        [,, $intake] = $this->setupForm([$this->field('file')]);
        $response = $this->response($intake);
        $file = $response->documents()->create(['field_id' => $intake->version->fields[0]['id'], 'path' => 'private/file.docx', 'original_name' => 'file.docx', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        Storage::disk('private')->put($file->path, '<script>privateDocument()</script>');

        $this->grant($response)->get(route('forms.document.preview', $file))->assertOk()
            ->assertSee('belum dapat ditampilkan')->assertSee(route('forms.document.download', $file))->assertDontSee('privateDocument');
    }

    public function test_hr_can_confirm_and_delete_empty_intake_with_pending_verifications(): void
    {
        [$admin, $template, $intake] = $this->setupForm();
        $token = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => 'pending@example.com', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addMinutes(30)]);

        Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'intake', $intake->id)
            ->assertSet('deletion.pending_tokens', 1)->assertSee('Belum ada pendaftar terverifikasi')
            ->call('deleteConfirmed')->assertHasNoErrors()->assertSet('deletion', []);
        $this->assertDatabaseMissing('form_intakes', ['id' => $intake->id]);
        $this->assertDatabaseMissing('form_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('candidate_forms', ['id' => $template->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.intake_deleted', 'target_id' => $intake->id]);
    }

    public function test_hr_can_delete_empty_form_and_its_versions_and_links(): void
    {
        [$admin, $template, $intake] = $this->setupForm();

        Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'form', $template->id)
            ->assertSet('deletion.responses', 0)->call('deleteConfirmed')->assertHasNoErrors();
        $this->assertDatabaseMissing('candidate_forms', ['id' => $template->id]);
        $this->assertDatabaseMissing('candidate_form_versions', ['id' => $intake->candidate_form_version_id]);
        $this->assertDatabaseMissing('form_intakes', ['id' => $intake->id]);
    }

    public function test_delete_preserves_applicants_even_when_they_arrive_after_confirmation(): void
    {
        [$admin, $template, $intake] = $this->setupForm();
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'intake', $intake->id);
        $response = $this->response($intake);

        $page->call('deleteConfirmed')->assertHasErrors('deletion');
        $page->call('confirmDelete', 'form', $template->id)->assertSet('deletion.responses', 1)
            ->assertSee('Tidak dapat dihapus permanen')->call('deleteConfirmed')->assertHasErrors('deletion');
        $this->assertDatabaseHas('form_responses', ['id' => $response->id]);
        $this->assertDatabaseHas('form_intakes', ['id' => $intake->id]);
        $this->assertDatabaseHas('candidate_forms', ['id' => $template->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.deleted']);
    }

    public function test_delete_requires_confirmation_and_rechecks_changed_links(): void
    {
        [$admin, $template, $intake] = $this->setupForm();
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('deleteConfirmed')->assertStatus(422);
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'form', $template->id);
        app(CandidateFormService::class)->createIntake($admin, $intake->only('candidate_form_version_id', 'position_id', 'recruitment_period_id'));

        $page->call('deleteConfirmed')->assertHasErrors('deletion');
        $this->assertDatabaseHas('candidate_forms', ['id' => $template->id]);
        $page->call('cancelDelete')->assertSet('deletion', []);
        Livewire::actingAs(User::factory()->create())->test(CandidateForms::class)->assertForbidden();
    }

    public function test_hr_can_delete_response_history_documents_and_tokens_without_deleting_linked_account(): void
    {
        Storage::fake('private');
        [$admin, $template, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $response = app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $other = $this->response($intake, 'other@example.com');
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/delete.pdf', 'original_name' => 'delete.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean', 'selected' => false]);
        Storage::disk('private')->put($document->path, '%PDF-1.4');
        $token = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => $response->email, 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addMinutes(30)]);
        $otherToken = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => $other->email, 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addMinutes(30)]);
        $dispatch = FormAccountDispatch::create(['form_response_id' => $response->id, 'actor_id' => $admin->id, 'application_data' => [], 'status' => 'sent']);

        Livewire::actingAs($admin)->test(CandidateForms::class)->call('inspect', $response->id)
            ->assertSee('Hapus respons')->call('confirmDelete', 'response', $response->id)
            ->assertSee('Konfirmasi hapus respons')->assertSee('1 riwayat pengiriman')->assertSee('1 dokumen')
            ->call('deleteConfirmed')->assertHasNoErrors()->assertSet('responseId', null)->assertSet('deletion', [])
            ->assertSee('Respons dan riwayat jawaban berhasil dihapus');

        $this->assertModelMissing($response);
        $this->assertModelMissing($document);
        $this->assertModelMissing($token);
        $this->assertModelMissing($dispatch);
        $this->assertDatabaseMissing('form_response_revisions', ['form_response_id' => $response->id]);
        Storage::disk('private')->assertMissing($document->path);
        $this->assertModelExists($other);
        $this->assertModelExists($otherToken);
        $this->assertModelExists($template);
        $this->assertModelExists($intake);
        $this->assertDatabaseHas('users', ['id' => $response->user_id]);
        $this->assertDatabaseHas('recruitment_applications', ['id' => $response->recruitment_application_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.response_deleted', 'target_id' => $response->id, 'actor_id' => $admin->id]);
        $this->grant($response)->get(route('forms.response', $response->reference))->assertNotFound();
        $this->get(route('forms.document.download', $document))->assertNotFound();
    }

    public function test_hr_can_cancel_then_delete_private_draft_without_seeing_its_answers(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $response->update(['name' => 'Nama rahasia draf', 'answers' => ['private' => 'Jawaban rahasia draf']]);
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('navigate', 'responses')
            ->assertSee('Hapus respons '.$response->email)->call('confirmDelete', 'response', $response->id)
            ->assertSee('Draf privat')->assertDontSee('Nama rahasia draf')->assertDontSee('Jawaban rahasia draf');

        $page->call('cancelDelete')->assertSet('deletion', []);
        $this->assertModelExists($response);
        $page->call('deleteConfirmed')->assertStatus(422);
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'response', $response->id)
            ->call('deleteConfirmed')->assertHasNoErrors();
        $this->assertModelMissing($response);
    }

    public function test_response_deletion_rechecks_new_documents_and_account_links_after_confirmation(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'response', $response->id);
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/new.pdf', 'original_name' => 'new.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);

        $page->call('deleteConfirmed')->assertHasErrors('deletion')->assertSee('Respons berubah sejak konfirmasi dibuka');
        $this->assertModelExists($document);
        $page->call('confirmDelete', 'response', $response->id);
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $page->call('deleteConfirmed')->assertHasErrors('deletion');
        $this->assertModelExists($response);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.response_deleted']);
    }

    #[TestWith(['pending'])]
    #[TestWith(['linked'])]
    public function test_response_deletion_is_blocked_while_account_linking_is_in_progress(string $status): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $dispatch = FormAccountDispatch::create(['form_response_id' => $response->id, 'actor_id' => $admin->id, 'application_data' => [], 'status' => $status]);

        Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'response', $response->id)
            ->call('deleteConfirmed')->assertHasErrors('deletion')->assertSee('Penghubungan akun masih diproses');

        $this->assertModelExists($response);
        $this->assertModelExists($dispatch);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.response_deleted']);
    }

    public function test_response_deletion_rechecks_admin_permission_on_confirmation(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'response', $response->id);
        $admin->update(['role' => Role::Candidate]);

        $page->call('deleteConfirmed')->assertForbidden();

        $this->assertModelExists($response);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.response_deleted']);
    }

    public function test_response_deletion_preserves_files_and_records_when_database_deletion_fails(): void
    {
        Storage::fake('private');
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/keep.pdf', 'original_name' => 'keep.pdf', 'mime' => 'application/pdf', 'size' => 10]);
        Storage::disk('private')->put($document->path, '%PDF-1.4');
        $service = app(CandidateFormService::class);
        $confirmed = $service->deletionSummary($admin, 'response', $response->id);
        FormResponse::deleting(function (): void {
            throw new \RuntimeException('Database deletion failed');
        });

        try {
            $service->deleteResponse($admin, $response->id, $confirmed);
            $this->fail('Kegagalan penghapusan database harus diteruskan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Database deletion failed', $exception->getMessage());
        } finally {
            FormResponse::flushEventListeners();
        }

        $this->assertModelExists($response);
        $this->assertModelExists($document);
        $this->assertDatabaseHas('form_response_revisions', ['form_response_id' => $response->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.response_deleted']);
        Storage::disk('private')->assertExists($document->path);
    }

    public function test_response_deletion_reports_failed_file_cleanup_without_restoring_broken_records(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $document = $response->documents()->create(['field_id' => (string) Str::uuid(), 'path' => 'candidate-forms/failed.pdf', 'original_name' => 'failed.pdf', 'mime' => 'application/pdf', 'size' => 10]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($document->path)->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with($document->path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        Exceptions::fake();

        Livewire::actingAs($admin)->test(CandidateForms::class)->call('confirmDelete', 'response', $response->id)
            ->call('deleteConfirmed')->assertHasErrors('deletion')->assertSet('deletion', [])
            ->assertSee('Sebagian file belum berhasil dibersihkan')->assertSee('Respons dan riwayat jawaban berhasil dihapus');

        $this->assertModelMissing($response);
        $this->assertModelMissing($document);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.response_deleted', 'target_id' => $response->id]);
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => str_contains($exception->getMessage(), $document->path));
    }

    public function test_validation_required_unknown_options_dates_and_stale_tabs(): void
    {
        $field = $this->field('select');
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $url = route('forms.save', $response->reference);
        $this->grant($response)->post($url, ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => [$field['id'] => 'Tiga'], 'action' => 'save'])->assertSessionHasErrors('answers.'.$field['id']);
        $this->post($url, ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => ['unknown' => 'value'], 'action' => 'save'])->assertSessionHasErrors('answers');
        $this->post($url, ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => [], 'action' => 'review', 'consent' => 1])->assertSessionHasErrors('answers.'.$field['id']);
        $this->post($url, ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => [], 'action' => 'save'])->assertSessionHasNoErrors();
        $this->post($url, ['lock_version' => 0, 'name' => 'Tab lain', 'answers' => [], 'action' => 'save'])->assertSessionHasErrors('conflict');
        $this->assertSame('Kandidat', $response->fresh()->name);
    }

    public function test_review_requires_ready_documents_but_incomplete_drafts_can_be_saved(): void
    {
        $field = $this->field('file');
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $url = route('forms.save', $response->reference);
        $data = ['lock_version' => 0, 'name' => 'Kandidat', 'consent' => 1, 'action' => 'review'];

        $this->grant($response)->post($url, $data)->assertSessionHasErrors('files.'.$field['id']);
        $this->assertSame(0, $response->fresh()->lock_version);
        $this->post($url, [...$data, 'action' => 'save'])->assertSessionHasNoErrors();
        $this->assertSame(1, $response->fresh()->lock_version);

        $document = $response->documents()->create(['field_id' => $field['id'], 'path' => 'private/cv.pdf', 'original_name' => 'CV.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'pending']);
        $this->post($url, [...$data, 'lock_version' => 1])->assertSessionHasErrors('files.'.$field['id']);
        $this->assertSame(1, $response->fresh()->lock_version);

        $document->update(['scan_status' => 'clean']);
        $this->post($url, [...$data, 'lock_version' => 1])->assertOk()->assertSee('Periksa sebelum dikirim');
        $this->assertSame(2, $response->fresh()->lock_version);
        $this->assertNull($response->fresh()->submitted_at);
    }

    public function test_missing_required_answers_return_clear_messages_and_preserve_input(): void
    {
        $field = $this->field('checkbox');
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);

        $this->grant($response)->from(route('forms.response', $response->reference))
            ->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => 'Kandidat', 'action' => 'review'])
            ->assertSessionHasInput('name', 'Kandidat')
            ->assertSessionHasErrors(['answers.'.$field['id'] => 'Pertanyaan checkbox wajib diisi.', 'consent' => 'Persetujuan pemrosesan data wajib disetujui.']);
        $this->assertSame(0, $response->fresh()->lock_version);
    }

    public function test_review_final_submit_and_duplicate_submit_create_one_revision(): void
    {
        [,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $this->grant($response)->post(route('forms.submit', $response->reference), ['lock_version' => 0, 'consent' => 1])->assertStatus(422);
        $this->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => 'Kandidat', 'answers' => [], 'action' => 'review', 'consent' => 1])->assertOk()->assertSee('Periksa sebelum dikirim');
        $this->post(route('forms.submit', $response->reference), ['lock_version' => 1, 'consent' => 1])->assertRedirect();
        $this->post(route('forms.submit', $response->reference), ['lock_version' => 1, 'consent' => 1])->assertRedirect();
        $this->assertSame(1, $response->revisions()->count());
        $this->get(route('forms.response', $response->reference))->assertOk()->assertSee('Terima kasih');
    }

    public function test_publication_freezes_old_questions_and_detects_stale_editor(): void
    {
        $field = $this->field();
        [$admin, $template, $intake] = $this->setupForm([$field]);
        $service = app(CandidateFormService::class);
        $template->refresh();
        $field['label'] = 'Pertanyaan yang diubah';
        $service->saveTemplate($admin, $template->id, $template->lock_version, ['title' => 'Judul baru', 'description' => '', 'fields' => [$field]]);
        $this->assertSame('Pertanyaan text', $intake->fresh()->version->fields[0]['label']);
        $this->expectException(ValidationException::class);
        $service->saveTemplate($admin, $template->id, $template->lock_version, ['title' => 'Tab lama', 'description' => '', 'fields' => []]);
    }

    public function test_deadline_closure_and_response_limit_are_enforced(): void
    {
        [,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $intake->update(['active' => false]);
        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => 'Nama', 'action' => 'save'])->assertStatus(422);
        $intake->update(['active' => true, 'deadline' => now()->subSecond()]);
        $this->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => 'Nama', 'action' => 'save'])->assertStatus(422);
        $intake->update(['deadline' => now()->addDay(), 'max_responses' => 1]);
        $this->finalized($response->fresh());
        $other = $this->response($intake, 'other@example.com');
        $this->expectException(ValidationException::class);
        $this->finalized($other);
    }

    public function test_upload_limits_scanning_and_final_file_requirement(): void
    {
        Queue::fake();
        Storage::fake('private');
        $field = $this->field('file');
        $field['max_files'] = 1;
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $this->grant($response)->post(route('forms.upload', $response->reference), ['field_id' => $field['id'], 'upload' => UploadedFile::fake()->create('bad.exe', 5)])->assertSessionHasErrors('upload');
        $file = app(FormDocumentService::class)->upload($response, $field['id'], UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf'));
        Queue::assertPushed(ScanFormDocument::class);
        $this->assertSame('pending', $file->scan_status);
        $this->get(route('forms.document.download', $file))->assertNotFound();
        try {
            $this->finalized($response->fresh());
            $this->fail('Pending files must block submission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('files.'.$field['id'], $e->errors());
        }
        $this->post(route('forms.upload', $response->reference), ['field_id' => $field['id'], 'upload' => UploadedFile::fake()->create('another.pdf', 5, 'application/pdf')])->assertSessionHasErrors('upload');
        $file->update(['scan_status' => 'clean']);
        $this->finalized($response->fresh());
        $this->assertDatabaseHas('form_responses', ['id' => $response->id, 'status' => 'submitted']);
    }

    public function test_revision_keeps_original_answers_and_documents(): void
    {
        Storage::fake('private');
        config(['submissions.scan_enabled' => false]);
        $field = $this->field('file', false);
        [$admin,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $file = app(FormDocumentService::class)->upload($response, $field['id'], UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf'));
        $response = $this->finalized($response->fresh());
        app(CandidateFormService::class)->revise($admin, $response, 'Perbaiki data pengalaman', now()->addDays(3)->toDateTimeString());
        app(FormDocumentService::class)->remove($file);
        $response = app(CandidateFormService::class)->save($response->fresh(), $response->fresh()->lock_version, ['name' => 'Nama revisi', 'answers' => [], 'consent' => true], true);
        $revisions = $response->revisions()->orderBy('number')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame('Nama dari formulir', $revisions[0]->name);
        $this->assertContains($file->id, $revisions[0]->document_ids);
        $this->assertSame([], $revisions[1]->document_ids);
        Storage::disk('private')->assertExists($file->path);
    }

    public function test_link_creates_candidate_once_and_revokes_public_access(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $this->grant($response)->get(route('forms.response', $response->reference))->assertOk();
        $linked = app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $this->assertTrue($linked->activation_pending);
        $this->assertSame(1, $linked->application->submissions()->count());
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $this->assertDatabaseCount('recruitment_applications', 1);
        $this->assertDatabaseCount('users', 2);
        $this->get(route('forms.response', $response->reference))->assertNotFound();
        $linked->user->update(['must_change_password' => false]);
        $this->actingAs($linked->user->fresh())->withSession(['portal_session_version' => 1])->get(route('forms.response', $response->reference))->assertOk();
    }

    public function test_link_existing_candidate_preserves_profile_documents_password_and_deadlines(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $user = User::factory()->create(['name' => 'Nama lama', 'email' => 'candidate@example.com']);
        $user->profile()->create(['phone' => '08123456789']);
        $submission = Submission::factory()->create(['recruitment_application_id' => RecruitmentApplication::factory()->create(['user_id' => $user->id, 'active_user_id' => $user->id, 'position_id' => $intake->position_id, 'recruitment_period_id' => $intake->recruitment_period_id])->id]);
        $beforeUser = $user->fresh()->getRawOriginal();
        $beforeSubmission = $submission->fresh()->getRawOriginal();
        $response = $this->finalized($this->response($intake));
        $linked = app(CandidateFormService::class)->link($admin, $response, []);
        $this->assertSame($user->id, $linked->user_id);
        $this->assertFalse($linked->activation_pending);
        $this->assertSame($beforeUser, $user->fresh()->getRawOriginal());
        $this->assertSame($beforeSubmission, $submission->fresh()->getRawOriginal());
        $this->assertSame('08123456789', $user->profile->phone);
    }

    public function test_conflicting_active_application_blocks_link_without_changing_existing_data(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $app = RecruitmentApplication::factory()->create();
        $response = $this->finalized($this->response($intake, $app->user->email));
        try {
            app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
            $this->fail('Conflict must block.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('link', $e->errors());
        }
        $this->assertNull($response->fresh()->user_id);
        $this->assertSame($app->user_id, $app->fresh()->active_user_id);
        $this->assertDatabaseCount('recruitment_applications', 1);
    }

    public function test_admin_email_cannot_be_linked_as_candidate(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake, $admin->email));
        $this->expectException(ValidationException::class);
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
    }

    public function test_legacy_candidate_without_forms_can_still_access_portal(): void
    {
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get('/portal')->assertOk();
        $this->get('/portal/profile')->assertOk();
        $this->get('/portal/forms')->assertOk()->assertSee('Belum ada pengisian formulir');
        $this->get(route('candidate.submission', $submission))->assertOk();
        $this->assertDatabaseCount('form_responses', 0);
    }

    public function test_builder_renders_saves_publishes_and_previews_fields(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('create')->set('formTitle', 'Formulir pertama')->call('addField')->set('fields.0.label', 'Alamat lengkap')->call('save')->assertHasNoErrors()->call('showPreview')->assertHasNoErrors()->assertSee('Pratinjau')->call('publish')->assertHasNoErrors()->assertSee('Tautan penerimaan');
        $this->assertDatabaseCount('candidate_form_versions', 1);
    }

    public function test_admin_can_review_final_response_but_not_private_draft(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->response($intake);
        $this->actingAs($admin)->withSession(['portal_session_version' => 1])->get(route('forms.response', $response->reference))->assertNotFound();
        $this->finalized($response);
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('inspect', $response->id)->assertSee('Ringkasan jawaban')->assertSee('Buat akun');
    }

    public function test_cleanup_preserves_final_history_and_legacy_data(): void
    {
        Storage::fake('private');
        [,, $intake] = $this->setupForm();
        $final = $this->finalized($this->response($intake));
        $draft = $this->response($intake, 'draft@example.com');
        $draft->update(['updated_at' => now()->subDays(40)]);
        $this->artisan('forms:cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('form_responses', ['id' => $draft->id]);
        $this->assertDatabaseHas('form_responses', ['id' => $final->id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_access_email_job_sends_private_link(): void
    {
        config(['mail.default' => 'array']);
        $this->freezeSecond();
        [,, $intake] = $this->setupForm();
        $raw = Str::random(64);
        $token = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => 'mail@example.com', 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(30)]);
        $this->travel(5)->minutes();
        (new SendFormAccess($token->id, $raw))->handle();
        $this->assertTrue($token->fresh()->expires_at->equalTo(now()->addDays(30)));
        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString(route('forms.verify', $raw), $html);
        $this->assertStringContainsString('Tautan dapat dibuka berkali-kali', $html);
        $this->assertStringContainsString('tidak dapat diubah', $html);
        $this->assertStringNotContainsString('hanya dapat digunakan sekali', $html);
    }

    public function test_every_supported_field_renders_and_validates(): void
    {
        $fields = array_map(fn ($type) => $this->field($type, false), array_keys(CandidateFormService::TYPES));
        [,, $intake] = $this->setupForm($fields);
        $this->get(route('forms.show', $intake->slug))->assertOk()->assertSee('Pertanyaan checkbox')->assertSee('Pertanyaan section');
        $answers = [];
        foreach ($fields as $field) {
            if (in_array($field['type'], ['file', 'section'])) {
                continue;
            }
            $answers[$field['id']] = match ($field['type']) {
                'email' => 'test@example.com',
                'phone' => '+62 812 345',
                'number' => '1.5',
                'numeric_text' => '0012345678901234',
                'date' => '2026-01-02',
                'radio', 'select' => 'Satu',
                'checkbox' => ['Satu', 'Dua'],
                'url' => 'https://example.com',
                'consent' => 1,
                default => 'Jawaban',
            };
        }
        $response = $this->finalized($this->response($intake), $answers);
        $this->assertSame(count($answers), count($response->answers));
        $this->grant($response)->get(route('forms.response', $response->reference))->assertOk()->assertSeeInOrder(['<li>Satu</li>', '<li>Dua</li>'], false);
    }

    #[TestWith(['0012345678901234'])]
    #[TestWith(['123'])]
    #[TestWith(['0'])]
    public function test_numeric_text_preserves_digits_and_limits_length_instead_of_numeric_value(string $value): void
    {
        $field = [...$this->field('numeric_text'), 'label' => 'No KTP', 'max_length' => 16];
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $this->get(route('forms.show', $intake->slug))->assertOk()
            ->assertSee('type="text" name="answers['.$field['id'].']"', false)
            ->assertSee('inputmode="numeric"', false)->assertSee('pattern="[0-9]*"', false)->assertSee('maxlength="16"', false);
        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => $response->name, 'answers' => [$field['id'] => $value], 'action' => 'review', 'consent' => true])
            ->assertOk()->assertSessionHasNoErrors()->assertSee($value);
        $this->assertSame($value, $response->fresh()->answers[$field['id']]);
    }

    #[TestWith(['12345678901234567'])]
    #[TestWith(['123abc'])]
    #[TestWith(['12.34'])]
    #[TestWith(['1e10'])]
    #[TestWith(['-123'])]
    #[TestWith(['+123'])]
    #[TestWith(['12 34'])]
    #[TestWith(["12\n34"])]
    #[TestWith(['１２３'])]
    #[TestWith([123])]
    public function test_numeric_text_rejects_invalid_values_even_when_saving_a_draft(string|int $value): void
    {
        $field = [...$this->field('numeric_text'), 'max_length' => 16];
        [,, $intake] = $this->setupForm([$field]);
        $response = $this->response($intake);
        $this->grant($response)->post(route('forms.save', $response->reference), ['lock_version' => 0, 'name' => $response->name, 'answers' => [$field['id'] => $value], 'action' => 'save'])
            ->assertSessionHasErrors('answers.'.$field['id']);
        $this->assertSame([], $response->fresh()->answers);
        $this->assertSame(0, $response->fresh()->lock_version);
    }

    public function test_editor_can_save_a_numeric_text_field_with_a_configurable_character_limit(): void
    {
        [$admin, $template] = $this->setupForm([$this->field()]);
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('edit', $template->id)
            ->set('fields.0.type', 'numeric_text')->assertSee('Maksimum karakter')
            ->set('fields.0.max_length', 16)->call('save')->assertHasNoErrors()->call('showPreview')->assertHasNoErrors();
        $this->assertSame('numeric_text', $template->fresh()->fields[0]['type']);
        $this->assertSame(16, $template->fresh()->fields[0]['max_length']);
    }

    public function test_expired_session_other_candidate_and_disabled_module_cannot_read_response(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $this->withSession(['form_access.'.$response->id => ['generation' => 1, 'expires' => now()->subSecond()->timestamp]])->get(route('forms.response', $response->reference))->assertNotFound();
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $this->actingAs(User::factory()->create())->withSession(['portal_session_version' => 1])->get(route('forms.response', $response->reference))->assertNotFound();
        config(['candidate_forms.enabled' => false]);
        $this->get(route('forms.show', $intake->slug))->assertNotFound();
        $this->get('/portal')->assertOk()->assertDontSee('Formulir saya');
    }

    public function test_candidate_cannot_use_admin_builder(): void
    {
        Livewire::actingAs(User::factory()->create())->test(CandidateForms::class)->assertForbidden();
    }

    public function test_email_rate_limit_is_shared_across_intakes(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $other = app(CandidateFormService::class)->createIntake($admin, $intake->only('candidate_form_version_id', 'position_id', 'recruitment_period_id'));
        $this->post(route('forms.access', $intake->slug), ['email' => 'frequent@example.com'])->assertRedirect();
        $this->post(route('forms.access', $other->slug), ['email' => 'FREQUENT@example.com'])->assertStatus(429)->assertHeader('Retry-After')->assertSee('Tunggu sebentar');
        $job = Queue::pushed(SendFormAccess::class)->first();
        $this->get(route('forms.verify', $job->rawToken))->assertOk();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        $this->travel(61)->seconds();
        $this->post(route('forms.access', $other->slug), ['email' => 'frequent@example.com'])->assertRedirect();
        Queue::assertPushed(SendFormAccess::class, 2);
    }

    public function test_old_three_email_attempts_do_not_block_legitimate_resend(): void
    {
        Queue::fake();
        [,, $intake] = $this->setupForm();
        $key = 'form-email:'.hash('sha256', 'retry@example.com');
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($key, 3600);
        }
        $this->post(route('forms.access', $intake->slug), ['email' => 'retry@example.com'])->assertRedirect();
        Queue::assertPushed(SendFormAccess::class, 1);
    }

    public function test_email_hourly_limit_remains_enforced_with_retry_guidance(): void
    {
        Queue::fake();
        [,, $intake] = $this->setupForm();
        for ($i = 0; $i < config('candidate_forms.email_max_per_hour'); $i++) {
            $this->post(route('forms.access', $intake->slug), ['email' => 'hourly@example.com', 'resend' => true])->assertRedirect();
            $this->travel(61)->seconds();
        }
        $this->postJson(route('forms.access', $intake->slug), ['email' => 'hourly@example.com', 'resend' => true])->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['message', 'retry_after']);
        Queue::assertPushed(SendFormAccess::class, config('candidate_forms.email_max_per_hour'));
    }

    public function test_waiting_refresh_and_repeated_form_post_do_not_send_more_emails(): void
    {
        Queue::fake();
        [,, $intake] = $this->setupForm();
        $url = route('forms.access', $intake->slug);
        $waiting = route('forms.waiting', $intake->slug);
        $this->post($url, ['email' => 'refresh@example.com', 'name' => 'Kandidat refresh'])->assertStatus(303)->assertRedirect($waiting);
        for ($i = 0; $i < 3; $i++) {
            $this->get($waiting)->assertOk()->assertSee('Refresh halaman ini tidak mengirim email baru.');
        }
        $this->travel(61)->seconds();
        $this->post($url, ['email' => 'REFRESH@example.com', 'name' => 'Kandidat refresh'])->assertStatus(303)->assertRedirect($waiting);
        Queue::assertPushed(SendFormAccess::class, 1);
        $this->assertDatabaseCount('form_access_tokens', 1);
        $this->assertSame(1, RateLimiter::attempts('form-email:'.hash('sha256', 'refresh@example.com')));
        $this->get(route('forms.show', $intake->slug))->assertSee('Kandidat refresh');
    }

    public function test_waiting_resend_requires_post_and_obeys_cooldown(): void
    {
        Queue::fake();
        [,, $intake] = $this->setupForm();
        $this->get(route('forms.waiting', $intake->slug))->assertRedirect(route('forms.show', $intake->slug));
        $this->post(route('forms.access', $intake->slug), ['email' => 'resend@example.com'])->assertStatus(303);
        $this->post(route('forms.resend', $intake->slug))->assertStatus(429);
        $this->travel(61)->seconds();
        $this->post(route('forms.resend', $intake->slug))->assertStatus(303);
        $this->get(route('forms.waiting', $intake->slug))->assertOk();
        Queue::assertPushed(SendFormAccess::class, 2);
        $job = Queue::pushed(SendFormAccess::class)->last();
        $this->post(route('forms.consume', $job->rawToken))->assertRedirect();
        $response = FormResponse::sole();
        $this->get(route('forms.waiting', $intake->slug))->assertRedirect(route('forms.response', $response->reference));
        $this->post(route('forms.access', $intake->slug), ['email' => 'resend@example.com'])->assertRedirect(route('forms.response', $response->reference));
        Queue::assertPushed(SendFormAccess::class, 2);
    }

    public function test_verification_throttle_renders_retry_guidance_without_consuming_valid_token(): void
    {
        [,, $intake] = $this->setupForm();
        $invalid = Str::random(64);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('forms.consume', $invalid))->assertStatus(410);
        }
        $raw = Str::random(64);
        $token = FormAccessToken::create(['form_intake_id' => $intake->id, 'email' => 'verify@example.com', 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(30)]);
        $this->post(route('forms.consume', $raw))->assertStatus(429)->assertHeader('Retry-After')->assertSee('Tunggu sebentar');
        $this->assertNull($token->fresh()->used_at);
        $this->travel(61)->seconds();
        $this->post(route('forms.consume', $raw))->assertRedirect();
    }

    public function test_activation_reset_enables_account_without_changing_original_response(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = app(CandidateFormService::class)->link($admin, $this->finalized($this->response($intake)), $this->applicationData());
        $token = Password::createToken($response->user);
        $this->post('/reset-password', ['token' => $token, 'email' => $response->user->email, 'password' => 'Candidate-new-password-123', 'password_confirmation' => 'Candidate-new-password-123'])->assertRedirect(route('login'));
        $this->assertFalse($response->fresh()->activation_pending);
        $this->assertFalse($response->user->fresh()->must_change_password);
        $this->assertSame(1, $response->revisions()->count());
    }

    public function test_create_account_from_response_uses_candidate_access_email_and_first_login_password_change(): void
    {
        Queue::fake();
        Notification::fake();
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)
            ->call('inspect', $response->id)
            ->set('application', $this->applicationData())->set('confirmLink', true)
            ->call('link')->assertHasNoErrors()->assertSee('Email akun kandidat');
        $delivery = AccessDelivery::sole();
        $user = $response->fresh()->user;
        $temporaryPassword = $delivery->password;
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
        $page->call('sendAccountEmail')->assertHasErrors('accountEmail');
        $this->assertDatabaseCount('access_deliveries', 1);
        (new SendCandidateAccess($delivery->id))->handle();
        $email = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Akses akun kandidat • VDNI', $email->getSubject());
        $this->assertStringContainsString($temporaryPassword, $email->getTextBody());
        $this->assertStringContainsString(route('login'), $email->getTextBody());
        $this->assertStringNotContainsString('/reset-password/', $email->getTextBody());
        $this->assertNull($delivery->fresh()->password);
        $this->actingAs($user)->withSession(['portal_session_version' => $user->session_version])
            ->post('/change-password', ['current_password' => $temporaryPassword, 'password' => 'Candidate-changed-password-123', 'password_confirmation' => 'Candidate-changed-password-123'])->assertRedirect(route('candidate.dashboard'));
        $this->assertFalse($response->fresh()->activation_pending);
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_legacy_activation_action_sends_candidate_account_email(): void
    {
        Queue::fake();
        Notification::fake();
        [$admin,, $intake] = $this->setupForm();
        $response = app(CandidateFormService::class)->link($admin, $this->finalized($this->response($intake)), $this->applicationData());

        Livewire::actingAs($admin)->test(CandidateForms::class)
            ->call('inspect', $response->id)
            ->call('sendActivation')->assertHasNoErrors()->assertSee('Email akun kandidat');

        $delivery = AccessDelivery::sole();
        $this->assertSame($response->user_id, $delivery->user_id);
        $this->assertTrue(Hash::check($delivery->password, $response->user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_link_existing_account_does_not_send_or_reset_candidate_access(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $hash = $user->password;
        $response = $this->finalized($this->response($intake, $user->email));
        Livewire::actingAs($admin)->test(CandidateForms::class)
            ->call('inspect', $response->id)->set('application', $this->applicationData())
            ->set('confirmLink', true)->call('link')->assertHasNoErrors();
        $this->assertDatabaseCount('access_deliveries', 0);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertFalse($response->fresh()->activation_pending);
    }

    public function test_stale_activation_flag_cannot_reset_password_already_chosen_by_candidate(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $service = app(CandidateFormService::class);
        $response = $service->link($admin, $this->finalized($this->response($intake)), $this->applicationData());
        $response->user->update(['must_change_password' => false]);
        $hash = $response->user->fresh()->password;
        try {
            $service->sendCandidateAccount($admin, $response);
            $this->fail('Password kandidat yang sudah aktif tidak boleh direset oleh tombol ini.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accountEmail', $exception->errors());
        }
        $this->assertSame($hash, $response->user->fresh()->password);
        $this->assertDatabaseCount('access_deliveries', 0);
    }

    public function test_deadlines_are_compared_in_portal_timezone(): void
    {
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $this->expectException(ValidationException::class);
        app(CandidateFormService::class)->revise($admin, $response, 'Perbaikan tanggal', now()->timezone(AppSetting::valueFor('timezone'))->subMinute()->format('Y-m-d\TH:i'));
    }

    public function test_bulk_preview_uses_all_filtered_final_responses_and_is_idempotent(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $first = $this->finalized($this->response($intake, 'first@example.com'));
        $second = $this->finalized($this->response($intake, 'second@example.com'));
        $this->response($intake, 'draft@example.com');
        [,, $other] = $this->setupForm();
        $this->finalized($this->response($other, 'other@example.com'));
        $page = Livewire::actingAs($admin)->test(CandidateForms::class)
            ->set('intakeFilter', (string) $intake->id)->set('application', $this->applicationData())
            ->call('previewBulkAccounts')->assertHasNoErrors()->assertSet('bulkResponseIds', [$first->id, $second->id]);
        $page->call('sendBulkAccounts')->assertHasErrors('confirmBulk');
        $this->assertDatabaseCount('form_account_dispatches', 0);
        $page->set('confirmBulk', true)->call('sendBulkAccounts')->assertHasNoErrors();
        $this->assertDatabaseCount('form_account_dispatches', 2);
        $this->assertSame(0, app(FormAccountBulkService::class)->start($admin, [$first->id, $second->id], $this->applicationData()));
        $this->assertDatabaseCount('form_account_dispatches', 2);
        $this->assertNull($first->fresh()->user_id);
        foreach (FormAccountDispatch::all() as $dispatch) {
            (new LinkFormCandidateAccount($dispatch->id))->handle(app(FormAccountBulkService::class));
        }
        $this->assertDatabaseCount('access_deliveries', 2);
        $this->assertNotNull($first->fresh()->user_id);
        $this->assertNotNull($second->fresh()->user_id);
        $dispatch = FormAccountDispatch::first();
        (new LinkFormCandidateAccount($dispatch->id))->handle(app(FormAccountBulkService::class));
        $this->assertDatabaseCount('access_deliveries', 2);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_bulk_existing_account_receives_login_notice_without_password_reset(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $user = User::factory()->create(['email' => 'existing-bulk@example.com']);
        $hash = $user->password;
        $response = $this->finalized($this->response($intake, $user->email));
        $service = app(FormAccountBulkService::class);
        $service->start($admin, [$response->id], $this->applicationData());
        $dispatch = FormAccountDispatch::sole();
        $service->process($dispatch->id);
        $this->assertSame('sent', $dispatch->fresh()->status);
        $this->assertSame($user->id, $response->fresh()->user_id);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('access_deliveries', 0);
        $email = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('Formulir terhubung', $email->getSubject());
        $this->assertStringContainsString(route('login'), $email->getHtmlBody());
        $service->process($dispatch->id);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_bulk_conflict_is_reported_and_other_candidate_can_continue(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $application = RecruitmentApplication::factory()->create();
        $hash = $application->user->password;
        $conflict = $this->finalized($this->response($intake, $application->user->email));
        $valid = $this->finalized($this->response($intake, 'valid-bulk@example.com'));
        $service = app(FormAccountBulkService::class);
        $service->start($admin, [$conflict->id, $valid->id], $this->applicationData());
        foreach (FormAccountDispatch::all() as $dispatch) {
            (new LinkFormCandidateAccount($dispatch->id))->handle($service);
        }
        $this->assertSame('failed', $conflict->fresh()->accountDispatch->status);
        $this->assertStringContainsString('berbeda', $conflict->accountDispatch->error);
        $this->assertNull($conflict->fresh()->user_id);
        $this->assertSame($hash, $application->user->fresh()->password);
        $this->assertSame('queued', $valid->fresh()->accountDispatch->status);
    }

    public function test_bulk_retry_resumes_linked_account_and_reuses_failed_email_password(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $service = app(FormAccountBulkService::class);
        $service->start($admin, [$response->id], $this->applicationData());
        $dispatch = FormAccountDispatch::sole();
        app(CandidateFormService::class)->link($admin, $response, $this->applicationData());
        $dispatch->update(['linked_at' => now(), 'status' => 'failed']);
        $service->retry($admin, $dispatch->id, []);
        $service->process($dispatch->id);
        $delivery = AccessDelivery::sole();
        $password = $delivery->password;
        $delivery->update(['status' => 'failed']);
        $service->retry($admin, $dispatch->id, []);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame($password, $delivery->fresh()->password);
        $this->assertDatabaseCount('access_deliveries', 1);
    }

    public function test_bulk_worker_rechecks_admin_access_before_creating_accounts(): void
    {
        Queue::fake();
        [$admin,, $intake] = $this->setupForm();
        $response = $this->finalized($this->response($intake));
        $service = app(FormAccountBulkService::class);
        $service->start($admin, [$response->id], $this->applicationData());
        $admin->update(['active' => false]);
        (new LinkFormCandidateAccount(FormAccountDispatch::sole()->id))->handle($service);
        $this->assertNull($response->fresh()->user_id);
        $this->assertSame('failed', FormAccountDispatch::sole()->status);
        $this->assertDatabaseCount('access_deliveries', 0);
    }

    public function test_dynamic_excel_preserves_types_final_snapshot_and_private_file_links(): void
    {
        $phone = $this->field('phone', false);
        $number = $this->field('number', false);
        $date = $this->field('date', false);
        $checkbox = $this->field('checkbox', false);
        $text = $this->field('text', false);
        $file = $this->field('file', false);
        $numericText = [...$this->field('numeric_text', false), 'max_length' => 16];
        [$admin,, $intake] = $this->setupForm([$phone, $number, $date, $checkbox, $text, $file, $numericText]);
        $response = $this->finalized($this->response($intake), [$phone['id'] => '08123456789', $number['id'] => 0, $date['id'] => '2000-01-02', $checkbox['id'] => ['Satu', 'Dua'], $text['id'] => '=HYPERLINK("https://example.com")', $numericText['id'] => '0012345678901234']);
        $document = $response->documents()->create(['field_id' => $file['id'], 'path' => 'secret/private-file.pdf', 'original_name' => 'CV.pdf', 'mime' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean']);
        $response->latestRevision->update(['document_ids' => [$document->id]]);
        $response->update(['status' => 'revision', 'name' => 'PRIVATE DRAFT NAME', 'answers' => [$text['id'] => 'PRIVATE DRAFT ANSWER']]);
        $this->response($intake, 'private-draft@example.com');
        $path = tempnam(sys_get_temp_dir(), 'form-export-');
        try {
            app(FormResponseExportService::class)->write($admin, ['intake' => $intake->id], $path);
            $reader = new Reader;
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $reader->close();
            $this->assertCount(2, $rows);
            $this->assertSame('Nama dari formulir', $rows[1][1]);
            $this->assertSame('08123456789', $rows[1][11]);
            $this->assertEquals(0, $rows[1][12]);
            $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][13]);
            $this->assertSame("Satu\nDua", $rows[1][14]);
            $this->assertSame('=HYPERLINK("https://example.com")', $rows[1][15]);
            $this->assertSame('CV.pdf', $rows[1][16]);
            $this->assertSame(route('forms.document.download', $document), $rows[1][17]);
            $this->assertSame('0012345678901234', $rows[1][18]);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertStringNotContainsString('PRIVATE DRAFT', $xml);
            $this->assertStringNotContainsString('secret/private-file', $xml);
        } finally {
            unlink($path);
        }
    }

    public function test_excel_separates_versions_and_livewire_download_is_available(): void
    {
        [$admin,, $first] = $this->setupForm([$this->field('text', false)]);
        [,, $second] = $this->setupForm([$this->field('number', false)]);
        $this->finalized($this->response($first, 'first@example.com'));
        $this->finalized($this->response($second, 'second@example.com'));
        $path = tempnam(sys_get_temp_dir(), 'form-export-');
        try {
            app(FormResponseExportService::class)->write($admin, [], $path);
            $reader = new Reader;
            $reader->open($path);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $sheet->getName();
            }
            $reader->close();
            $this->assertCount(2, array_unique($sheets));
        } finally {
            unlink($path);
        }
        Livewire::actingAs($admin)->test(CandidateForms::class)->call('exportResponses')->assertFileDownloaded();
    }

    public function test_pending_form_migration_does_not_break_legacy_portal(): void
    {
        Schema::drop('form_access_tokens');
        $submission = Submission::factory()->create();
        $user = $submission->application->user;
        $this->actingAs($user)->withSession(['portal_session_version' => 1])->get('/portal')->assertOk()->assertDontSee('Formulir saya');
        $this->get('/portal/profile')->assertOk();
        $this->artisan('forms:cleanup')->assertSuccessful();
        $this->assertFalse(CandidateFormService::available());
    }
}
