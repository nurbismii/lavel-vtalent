<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Psychometrics;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\MbtiService;
use App\Services\PsychometricResultExportService;
use App\Services\PsychometricService;
use Database\Seeders\PsychometricTestSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MbtiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function reportTypes(): array
    {
        return [
            'ISTJ' => ['ISTJ', 'Penata yang konsisten'],
            'ISFJ' => ['ISFJ', 'Pendamping yang penuh perhatian'],
            'INFJ' => ['INFJ', 'Perangkai makna dan arah'],
            'INTJ' => ['INTJ', 'Perancang arah jangka panjang'],
            'ISTP' => ['ISTP', 'Pengurai masalah praktis'],
            'ISFP' => ['ISFP', 'Pengamat yang menghargai keunikan'],
            'INFP' => ['INFP', 'Penjelajah nilai dan kemungkinan'],
            'INTP' => ['INTP', 'Penelaah konsep dan alasan'],
            'ESTP' => ['ESTP', 'Penggerak tindakan langsung'],
            'ESFP' => ['ESFP', 'Penghidup pengalaman bersama'],
            'ENFP' => ['ENFP', 'Penghubung ide dan antusiasme'],
            'ENTP' => ['ENTP', 'Penguji gagasan dan alternatif'],
            'ESTJ' => ['ESTJ', 'Pengarah pelaksanaan yang terstruktur'],
            'ESFJ' => ['ESFJ', 'Perawat kerja sama dan kebersamaan'],
            'ENFJ' => ['ENFJ', 'Penggerak tujuan bersama'],
            'ENTJ' => ['ENTJ', 'Penyusun strategi dan pelaksanaan'],
        ];
    }

    #[DataProvider('reportTypes')]
    public function test_candidate_sees_their_complete_type_report_and_working_contents_links(string $type, string $name): void
    {
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $result = app(MbtiService::class)->score($test->sections, $this->answersFor($test->sections, $type));
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $this->loginFor($attempt);

        $response = $this->get(route('candidate.psychometrics.show', $attempt))
            ->assertSee('Laporan terperinci '.$type)->assertSee($name)
            ->assertSee('Saran pengembangan')->assertSee('Langkah praktis dan refleksi')
            ->assertDontSee('Cara membaca hasil Anda')->assertSee('Contoh situasi dan respons')
            ->assertSee('Rencana pengembangan 4 minggu')->assertSee('12 bagian');

        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $illustration = $document->querySelector('.mbti-character');
        $this->assertSame(asset('images/mbti/'.strtolower($type).'.webp'), $illustration->getAttribute('src'));
        $this->assertSame('Ilustrasi '.$name, $illustration->getAttribute('alt'));
        $assetPath = public_path('images/mbti/'.strtolower($type).'.webp');
        $this->assertFileExists($assetPath);
        $this->assertSame('image/webp', getimagesize($assetPath)['mime']);
        $report = $document->querySelector('.mbti-report');
        $this->assertTrue($report->hasAttribute('open'));
        $this->assertCount(12, $report->querySelectorAll('.mbti-report-section'));
        $links = $report->querySelectorAll('.mbti-report-toc a');
        $this->assertCount(12, $links);
        $this->assertNull($report->querySelector('#mbti-attempt-'.$attempt->id.'-scores'));
        foreach ($links as $link) {
            $heading = $report->querySelector($link->getAttribute('href'));
            $this->assertNotNull($heading);
            $this->assertSame(trim($link->textContent), trim($heading->textContent));
            $this->assertGreaterThan(0, $heading->parentNode->querySelectorAll('p, li')->count());
        }
        foreach (['situations' => 3, 'plan' => 4] as $section => $count) {
            $heading = $report->querySelector('#mbti-attempt-'.$attempt->id.'-'.$section);
            $this->assertCount($count, $heading->parentNode->querySelectorAll('li'));
        }
        $this->assertSame('https://www.mbtionline.com/mbti-types/'.strtolower($type), $report->querySelector('.mbti-report-source a')->getAttribute('href'));
        $this->assertSame($result, $attempt->fresh()->result);
    }

    /** @return array<string, array{string, string}> */
    public static function balancedReportTypes(): array
    {
        return [
            'EI balanced' => ['XSTJ', 'E / I · Jawaban E dan I seimbang'],
            'SN balanced' => ['EXFJ', 'S / N · Jawaban S dan N seimbang'],
            'TF balanced' => ['ISXP', 'T / F · Jawaban T dan F seimbang'],
            'JP balanced' => ['INFX', 'J / P · Jawaban J dan P seimbang'],
            'two balanced pairs' => ['XXTJ', 'T · Jawaban lebih condong pada konsistensi alasan'],
            'three balanced pairs' => ['EXXX', 'E · Jawaban lebih condong pada interaksi'],
            'all pairs balanced' => ['XXXX', 'E / I · Jawaban E dan I seimbang'],
        ];
    }

    #[DataProvider('balancedReportTypes')]
    public function test_balanced_results_show_a_complete_dimension_report_without_forcing_a_type(string $type, string $description): void
    {
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $answers = [array_replace(array_fill(1, 40, ['A']), $this->answersFor($test->sections, $type)[0] ?? [])];
        $result = app(MbtiService::class)->score($test->sections, $answers);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $this->loginFor($attempt);

        $response = $this->get(route('candidate.psychometrics.show', $attempt))
            ->assertSee('Laporan terperinci '.$type)->assertSee('Profil dengan preferensi seimbang')
            ->assertSee($description)->assertSee('Saran pengembangan')
            ->assertDontSee('Cara membaca hasil Anda')->assertSee('12 bagian')
            ->assertDontSee('Laporan terperinci belum ditampilkan')
            ->assertDontSee('https://www.mbtionline.com/mbti-types/', false);

        $this->assertSame($type, $result['type']);
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $illustration = $document->querySelector('.mbti-character');
        $this->assertSame(asset('images/mbti/balanced.webp'), $illustration->getAttribute('src'));
        $this->assertSame('Ilustrasi Profil dengan preferensi seimbang', $illustration->getAttribute('alt'));
        $this->assertFileExists(public_path('images/mbti/balanced.webp'));
        $report = $document->querySelector('.mbti-report');
        $this->assertTrue($report->hasAttribute('open'));
        $this->assertCount(12, $report->querySelectorAll('.mbti-report-section'));
        $this->assertNull($report->querySelector('#mbti-attempt-'.$attempt->id.'-scores'));
        foreach (['traits' => 4, 'strengths' => 4, 'challenges' => 4, 'growth' => 4, 'situations' => 3, 'plan' => 4] as $section => $count) {
            $heading = $report->querySelector('#mbti-attempt-'.$attempt->id.'-'.$section);
            $this->assertCount($count, $heading->parentNode->querySelectorAll('li'));
        }
        $this->assertSame($result, $attempt->fresh()->result);
    }

    public function test_incomplete_results_still_explain_why_no_type_report_is_shown(): void
    {
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $result = app(MbtiService::class)->score($test->sections, [[1 => ['A']]]);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $this->loginFor($attempt);

        $response = $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Jawaban belum lengkap.');

        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $this->assertCount(0, $document->querySelectorAll('.mbti-report'));
        $this->assertCount(0, $document->querySelectorAll('.mbti-character'));
    }

    #[TestWith(['ESTJ'])]
    #[TestWith(['XSTJ'])]
    #[TestWith(['XXXX'])]
    public function test_hr_can_open_multiple_reports_of_the_same_type_with_unique_contents_targets(string $type): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $answers = [array_replace(array_fill(1, 40, ['A']), $this->answersFor($test->sections, $type)[0] ?? [])];
        $result = app(MbtiService::class)->score($test->sections, $answers);
        $attempts = PsychometricAttempt::factory()->count(2)->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        foreach ($attempts as $index => $attempt) {
            $attempt->application->user->update(['name' => ['Budi Santoso', 'Siti <Putri> & Ayu'][$index]]);
        }

        $page = Livewire::actingAs($admin)->test(Psychometrics::class)
            ->assertSee('Laporan terperinci '.$type)->assertDontSee('Cara membaca hasil')->assertSee('12 bagian');

        $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
        $reports = $document->querySelectorAll('.mbti-report');
        $this->assertCount(2, $reports);
        $ids = [];
        foreach ($reports as $report) {
            $this->assertFalse($report->hasAttribute('open'));
            $this->assertCount(12, $report->querySelectorAll('.mbti-report-section'));
            foreach ($report->querySelectorAll('.mbti-report-toc a') as $link) {
                $heading = $report->querySelector($link->getAttribute('href'));
                $this->assertNotNull($heading);
                $ids[] = $heading->getAttribute('id');
            }
        }
        $this->assertCount(24, array_unique($ids));
        foreach ($attempts as $attempt) {
            $target = '#mbti-attempt-'.$attempt->id.'-scores';
            $this->assertNull($document->querySelector($target));
            $this->assertNull($document->querySelector('a[href="'.$target.'"]'));
        }
        $this->assertSame('Cara membaca hasil Anda', config('mbti_reports.sections.scores'));
    }

    /** @return array<string, array{string, int, string, string, string, int}> */
    public static function personalizedReadings(): array
    {
        return [
            'all choices on one side' => ['ESTJ', 0, 'ESTJ', 'E/I: E 10 dari 10 jawaban (100%) dan I 0 dari 10 jawaban (0%). Selisih 10 jawaban.', 'Semua jawaban tersimpan pada pasangan ini mendukung E.', 3],
            'both sides represented' => ['ESTJ', 2, 'ESTJ', 'E/I: E 8 dari 10 jawaban (80%) dan I 2 dari 10 jawaban (20%). Selisih 6 jawaban.', 'Tetap periksa kapan pendekatan I membantu', 3],
            'one changed answer would tie' => ['ESTJ', 4, 'ESTJ', 'E/I: E 6 dari 10 jawaban (60%) dan I 4 dari 10 jawaban (40%). Selisih 2 jawaban.', 'Jika satu jawaban E berubah ke I, jumlah kedua sisi menjadi sama.', 4],
            'opposite side leads by one changed answer' => ['INFP', 4, 'INFP', 'E/I: E 4 dari 10 jawaban (40%) dan I 6 dari 10 jawaban (60%). Selisih 2 jawaban.', 'Jika satu jawaban I berubah ke E, jumlah kedua sisi menjadi sama.', 4],
            'equal choices keep X' => ['ESTJ', 5, 'XSTJ', 'E/I: E 5 dari 10 jawaban (50%) dan I 5 dari 10 jawaban (50%). Selisih 0 jawaban.', 'Jumlah pilihan sama, sehingga pasangan ini ditandai X.', 4],
            'close pair beside a balanced pair' => ['EXFJ', 4, 'EXFJ', 'E/I: E 6 dari 10 jawaban (60%) dan I 4 dari 10 jawaban (40%). Selisih 2 jawaban.', 'Jika satu jawaban E berubah ke I, jumlah kedua sisi menjadi sama.', 5],
        ];
    }

    #[DataProvider('personalizedReadings')]
    public function test_report_explains_stored_counts_and_adds_reflection_for_close_pairs_without_changing_results(string $sourceType, int $flips, string $expectedType, string $reading, string $explanation, int $growthItems): void
    {
        $test = PsychometricTest::factory()->legacyMbti()->published()->create();
        $answers = [array_replace(array_fill(1, 40, ['A']), $this->answersFor($test->sections, $sourceType)[0])];
        $opposite = str_starts_with($sourceType, 'E') ? 'I' : 'E';
        foreach ($test->sections[0]['questions'] as $number => $question) {
            if ($question['dimension'] === 'EI' && $flips > 0) {
                $answers[0][$number + 1] = [array_search($opposite, $question['preferences'], true)];
                $flips--;
            }
        }
        $result = app(MbtiService::class)->score($test->sections, $answers);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $page = Livewire::actingAs($admin)->test(Psychometrics::class)
            ->assertSee('Laporan terperinci '.$expectedType)->assertDontSee('Cara membaca hasil')->assertDontSee($reading);

        $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR);
        $scores = app(MbtiService::class)->report($result)['scores'];
        $this->assertStringStartsWith($reading, $scores[2]);
        $this->assertStringContainsString($explanation, $scores[2]);
        $this->assertStringContainsString('bukan persentil', $scores[0]);
        $growth = $document->querySelector('#mbti-attempt-'.$attempt->id.'-growth')->parentNode->querySelectorAll('li');
        $this->assertCount($growthItems, $growth);
        $this->assertSame($expectedType, $attempt->fresh()->result['type']);
        $this->assertSame($result, $attempt->fresh()->result);
    }

    public function test_admin_can_create_publish_and_assign_mbti_alongside_iq(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $application = RecruitmentApplication::factory()->create();
        $page = Livewire::actingAs($admin)->test(Psychometrics::class)
            ->call('createDraft')->assertHasNoErrors();
        $iq = PsychometricTest::sole();
        $this->assertSame('iq', $iq->type);
        $page->call('createDraft', 'mbti')->assertHasNoErrors()
            ->assertSee('Saya mendapat energi')->assertSee('Sangat tidak setuju')->assertDontSee('wire:model="keyText.', false)
            ->call('save', true)->assertHasErrors('reviewed')
            ->set('durations.0', 0)->set('reviewed', true)->call('save', true)->assertHasErrors('duration')
            ->set('durations.0', 900)->call('save', true)->assertHasNoErrors();
        $mbti = PsychometricTest::where('type', 'mbti')->sole();
        $this->assertNotNull($mbti->published_at);
        $this->assertSame([], $mbti->answer_key);
        $this->assertSame(900, $mbti->sections[0]['seconds']);
        $this->assertSame(7, $mbti->sections[0]['options']);
        $this->assertSame('likert7', $mbti->sections[0]['answer_format']);
        $this->assertNull($iq->fresh()->published_at);
        $service = app(PsychometricService::class);
        $first = $service->assign($admin, $mbti->id, $application->id, now()->toDateTimeString(), now()->addDay()->toDateTimeString());
        $again = $service->assign($admin, $mbti->id, $application->id, now()->toDateTimeString(), now()->addDays(2)->toDateTimeString());
        $this->assertSame($first->id, $again->id);
        $this->assertTrue($first->deadline->equalTo($again->deadline));
        $iq->update(['published_at' => now()]);
        $service->assign($admin, $iq->id, $application->id, now()->toDateTimeString(), now()->addDay()->toDateTimeString());
        $this->assertDatabaseCount('psychometric_attempts', 2);
        $page->call('createDraft', 'invalid')->assertStatus(422);
    }

    public function test_questionnaire_has_balanced_pairs_and_scores_all_sixteen_types_from_its_snapshot(): void
    {
        $service = app(MbtiService::class);
        $sections = $service->sections();
        $this->assertCount(40, $sections[0]['questions']);
        config(['mbti.questions' => []]);
        foreach (array_keys(config('mbti.profiles')) as $type) {
            $result = $service->score($sections, $this->answersFor($sections, $type));
            $this->assertSame($type, $result['type']);
            $this->assertSame(40, $result['answered']);
            foreach ($result['dimensions'] as $pair => $dimension) {
                $this->assertSame(10, $dimension['total']);
                $this->assertSame(10, $dimension['answered']);
                $this->assertContains($dimension['preference'], str_split($pair));
                $this->assertSame(100, $dimension['percentages'][$dimension['preference']]);
                $this->assertSame(100, array_sum($dimension['percentages']));
            }
        }
    }

    public function test_tied_and_incomplete_answers_do_not_invent_a_dominant_preference(): void
    {
        $service = app(MbtiService::class);
        $sections = $service->sections();
        $tie = $service->score($sections, [array_fill(1, 40, ['A'])]);
        $this->assertSame('XXXX', $tie['type']);
        foreach ($tie['dimensions'] as $dimension) {
            $this->assertNull($dimension['preference']);
            $this->assertSame([50, 50], array_values($dimension['percentages']));
        }
        $partial = $service->score($sections, [[1 => ['A']]]);
        $this->assertNull($partial['type']);
        $this->assertSame(1, $partial['answered']);
        $this->assertSame(['E' => 100, 'I' => 0], $partial['dimensions']['EI']['percentages']);
        $this->assertSame(['S' => null, 'N' => null], $partial['dimensions']['SN']['percentages']);
    }

    public function test_candidate_can_resume_and_finish_mbti_once_with_private_validated_answers(): void
    {
        $this->freezeTime();
        $test = PsychometricTest::factory()->legacyMbti()->create(['published_at' => now()]);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $url = route('candidate.psychometrics.show', $attempt);
        $this->get($url)->assertRedirect(route('login'));
        $this->loginFor($attempt);
        $this->get($url)->assertOk()->assertSee('Tidak ada jawaban benar atau salah')->assertDontSee('preferences');
        $this->get(route('candidate.psychometrics.image', [$attempt, 2]))->assertNotFound();
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertOk();
        $expiry = $attempt->fresh()->section_expires_at;
        $this->travel(10)->seconds();
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertOk();
        $this->assertTrue($expiry->equalTo($attempt->fresh()->section_expires_at));
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['A']]])->assertJsonPath('revision', 1);
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['B']]])->assertConflict();
        foreach ([[1 => ['C']], [1 => ['G']], [1 => ['A', 'B']], [41 => ['A']]] as $answers) {
            $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 1, 'answers' => $answers])->assertUnprocessable();
        }
        $response = $this->get($url)->assertOk()->assertDontSee('preferences')->assertDontSee('Gambar soal');
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $this->assertCount(40, $document->querySelectorAll('fieldset.psych-question-card'));
        $this->assertCount(80, $document->querySelectorAll('input[type="radio"]'));
        $this->assertCount(80, $document->querySelectorAll('input[type="radio"][required]'));
        $this->assertTrue($document->querySelector('button[value="save"]')->hasAttribute('formnovalidate'));
        $this->assertTrue($document->querySelector('input[name="answers[1][]"][value="A"]')->hasAttribute('checked'));
        $payload = ['action' => 'finish', 'section' => 0, 'revision' => 1, 'answers' => $this->answersFor($test->sections, 'ESTJ')[0]];
        $this->postJson($url, $payload)->assertJsonPath('completed', true)->assertJsonMissingPath('result')->assertJsonMissingPath('raw_score');
        $completed = $attempt->fresh();
        $this->assertSame('ESTJ', $completed->result['type']);
        $this->assertNull($completed->raw_score);
        $this->assertNull($completed->iqScore());
        $this->assertArrayNotHasKey('result', $completed->toArray());
        $this->postJson($url, $payload)->assertJsonPath('completed', true);
        $this->assertSame(1, AuditLog::where('action', 'psychometric.completed')->count());
        $this->get($url)->assertSee('ESTJ')->assertSee('Hasil MBTI')->assertDontSee('Skor mentah');
        $this->get(route('candidate.psychometrics'))->assertSee('Lihat hasil MBTI');
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['portal_session_version' => $other->session_version]);
        $this->get($url)->assertNotFound();
        $this->postJson($url, $payload)->assertNotFound();
    }

    #[TestWith([false, 'empty'])]
    #[TestWith([false, 'missing'])]
    #[TestWith([false, 'blank'])]
    #[TestWith([false, 'aliased'])]
    #[TestWith([true, 'empty'])]
    #[TestWith([true, 'missing'])]
    #[TestWith([true, 'blank'])]
    #[TestWith([true, 'aliased'])]
    public function test_mbti_requires_every_answer_to_finish_but_keeps_partial_autosaves(bool $legacy, string $incomplete): void
    {
        $this->freezeTime();
        $test = ($legacy ? PsychometricTest::factory()->legacyMbti() : PsychometricTest::factory()->mbti())->published()->create();
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.update', $attempt);
        $choice = $legacy ? 'A' : 'D';
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertOk();
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => [$choice]]])->assertJsonPath('revision', 1);
        $original = $attempt->fresh()->getAttributes();
        $completeAnswers = array_fill(1, $test->sections[0]['count'], [$choice]);
        $answers = $completeAnswers;
        if ($incomplete === 'empty') {
            $answers = [];
        } elseif ($incomplete === 'missing') {
            unset($answers[40]);
        } elseif ($incomplete === 'blank') {
            $answers[40] = [];
        } else {
            unset($answers[40]);
            $answers['01'] = [$choice];
        }

        $this->postJson($url, ['action' => 'finish', 'section' => 0, 'revision' => 1, 'answers' => $answers])
            ->assertUnprocessable()->assertJsonValidationErrors($incomplete === 'blank' ? 'answers.40' : 'answers');
        $this->assertSame($original, $attempt->fresh()->getAttributes());
        $this->assertDatabaseMissing('audit_logs', ['target_id' => $attempt->id, 'action' => 'psychometric.completed']);

        $this->postJson($url, ['action' => 'finish', 'section' => 0, 'revision' => 1, 'answers' => $completeAnswers])
            ->assertJsonPath('completed', true);
        $this->assertSame(40, $attempt->fresh()->result['answered']);
        $this->assertNotNull($attempt->fresh()->result['type']);
        $this->get(route('candidate.psychometrics.show', $attempt))->assertSee('Laporan terperinci');
    }

    #[TestWith(['A', 6, 0, 100])]
    #[TestWith(['B', 5, 1, 83])]
    #[TestWith(['C', 4, 2, 67])]
    #[TestWith(['D', 3, 3, 50])]
    #[TestWith(['E', 2, 4, 33])]
    #[TestWith(['F', 1, 5, 17])]
    #[TestWith(['G', 0, 6, 0])]
    public function test_seven_choice_weights_reverse_with_the_statement_and_do_not_complete_partial_answers(string $choice, int $agree, int $opposite, int $percentage): void
    {
        $service = app(MbtiService::class);
        $sections = $service->sections();

        $forward = $service->score($sections, [[1 => [$choice]]]);
        $reverse = $service->score($sections, [[5 => [$choice]]]);

        $this->assertSame(['E' => $agree, 'I' => $opposite], $forward['dimensions']['EI']['scores']);
        $this->assertSame(['E' => $opposite, 'I' => $agree], $reverse['dimensions']['EI']['scores']);
        $this->assertSame(['E' => $percentage, 'I' => 100 - $percentage], $forward['dimensions']['EI']['percentages']);
        $this->assertSame(1, $forward['answered']);
        $this->assertSame($choice === 'D' ? 1 : 0, $forward['dimensions']['EI']['neutral']);
        $this->assertNull($forward['type']);
        $this->assertNull($service->report($forward));
        $this->assertSame(['S' => null, 'N' => null], $forward['dimensions']['SN']['percentages']);
    }

    public function test_candidate_can_resume_seven_choices_and_complete_all_neutral_with_a_balanced_report(): void
    {
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.show', $attempt);
        $this->get($url)->assertSee('tujuh tingkat persetujuan');
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertOk();
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['G'], 2 => ['D']]])->assertJsonPath('revision', 1);
        $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 0, 'answers' => [1 => ['A']]])->assertConflict();
        foreach ([[1 => ['H']], [1 => [0]], [1 => ['A', 'G']], [41 => ['A']]] as $answers) {
            $this->postJson($url, ['action' => 'save', 'section' => 0, 'revision' => 1, 'answers' => $answers])->assertUnprocessable();
        }

        $response = $this->get($url)->assertSee('Pilihan: Sangat tidak setuju')->assertSee('Pilihan: Netral');
        $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        $this->assertCount(280, $document->querySelectorAll('input[type="radio"]'));
        $this->assertCount(280, $document->querySelectorAll('input[type="radio"][required]'));
        $this->assertTrue($document->querySelector('button[value="save"]')->hasAttribute('formnovalidate'));
        $this->assertCount(40, $document->querySelectorAll('.mbti-statement'));
        $this->assertTrue($document->querySelector('input[name="answers[1][]"][value="G"]')->hasAttribute('checked'));
        $this->assertSame([[1 => ['G'], 2 => ['D']]], $attempt->fresh()->answers);
        $payload = ['action' => 'finish', 'section' => 0, 'revision' => 1, 'answers' => array_fill(1, 40, ['D'])];
        $this->postJson($url, $payload)->assertJsonPath('completed', true)->assertJsonMissingPath('result');
        $this->postJson($url, $payload)->assertJsonPath('completed', true);

        $result = $attempt->fresh()->result;
        $this->assertSame('XXXX', $result['type']);
        $this->assertSame('likert7', $result['answer_format']);
        $this->assertSame(40, $result['answered']);
        foreach ($result['dimensions'] as $dimension) {
            $this->assertSame([30, 30], array_values($dimension['scores']));
            $this->assertSame([50, 50], array_values($dimension['percentages']));
            $this->assertSame(10, $dimension['neutral']);
        }
        $this->assertSame(1, AuditLog::where('action', 'psychometric.completed')->count());
        $this->get($url)->assertSee('Laporan terperinci XXXX')->assertSee('12 bagian')
            ->assertDontSee('Cara membaca hasil Anda')->assertDontSee('30 dari 60 poin')
            ->assertDontSee('Pilihan netral: 10 dari 10 jawaban');
        $admin = User::factory()->create(['role' => Role::Admin]);
        Livewire::actingAs($admin)->test(Psychometrics::class)->assertSee('D. Netral')->assertDontSee('30 dari 60 poin')
            ->assertDontSee('Pilihan netral: 10 dari 10 jawaban')->assertSee('12 bagian');
    }

    #[TestWith(['C', 'EXXX', 31, 29, 'I'])]
    #[TestWith(['E', 'IXXX', 29, 31, 'E'])]
    public function test_weighted_close_pair_report_explains_points_and_one_scale_step(string $choice, string $type, int $ePoints, int $iPoints, string $opposite): void
    {
        $test = PsychometricTest::factory()->mbti()->published()->create();
        $answers = [array_replace(array_fill(1, 40, ['D']), [1 => [$choice]])];
        $result = app(MbtiService::class)->score($test->sections, $answers);
        $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        Livewire::actingAs($admin)->test(Psychometrics::class)->assertSee('Laporan terperinci '.$type)
            ->assertDontSee('Cara membaca hasil')->assertDontSee('E '.$ePoints.' dari 60 poin')
            ->assertDontSee('I '.$iPoints.' dari 60 poin')->assertDontSee('Pilihan netral: 9 dari 10 jawaban')
            ->assertDontSee('Jika satu jawaban');

        $scores = app(MbtiService::class)->report($result)['scores'][2];
        $this->assertStringContainsString('E '.$ePoints.' dari 60 poin', $scores);
        $this->assertStringContainsString('I '.$iPoints.' dari 60 poin', $scores);
        $this->assertStringContainsString('Pilihan netral: 9 dari 10 jawaban', $scores);
        $this->assertStringContainsString('Perubahan satu tingkat jawaban ke arah '.$opposite, $scores);
        $this->assertSame($result, $attempt->fresh()->result);
    }

    public function test_deadline_finalizes_partial_and_empty_mbti_without_an_iq_or_complete_type(): void
    {
        $this->freezeTime();
        $test = PsychometricTest::factory()->mbti()->create(['published_at' => now()]);
        $partial = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'deadline' => now(), 'answers' => [[1 => ['A']]]]);
        $empty = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'deadline' => now()]);
        $this->artisan('psychometrics:finalize')->assertSuccessful();
        $this->artisan('psychometrics:finalize')->assertSuccessful();
        $this->assertNull($partial->fresh()->result['type']);
        $this->assertSame(1, $partial->fresh()->result['answered']);
        $this->assertSame(0, $empty->fresh()->result['answered']);
        $this->assertNull($empty->fresh()->result['type']);
        $this->assertNull($partial->fresh()->iqScore());
        $this->assertSame(2, AuditLog::where('action', 'psychometric.completed')->count());
        $this->loginFor($partial);
        $this->get(route('candidate.psychometrics.show', $partial))->assertOk()->assertSee('Jawaban belum lengkap');
        $admin = User::factory()->create(['role' => Role::Admin]);
        Livewire::actingAs($admin)->test(Psychometrics::class)->assertSee('Jawaban belum lengkap');
    }

    public function test_mbti_sections_expire_and_cannot_be_started_before_the_schedule(): void
    {
        $this->freezeTime();
        $attempt = PsychometricAttempt::factory()->create([
            'psychometric_test_id' => PsychometricTest::factory()->mbti()->create(['published_at' => now()]),
            'opens_at' => now()->addMinute(),
        ]);
        $this->loginFor($attempt);
        $url = route('candidate.psychometrics.show', $attempt);
        $this->postJson($url, ['action' => 'start', 'section' => 0])->assertForbidden();
        $attempt->update(['opens_at' => now()->subMinute(), 'section_started_at' => now()->subHour(), 'section_expires_at' => now()]);
        $this->get($url)->assertOk()->assertSee('Belum lengkap');
        $this->assertNotNull($attempt->fresh()->completed_at);
    }

    public function test_results_and_excel_filter_mbti_and_keep_iq_columns_separate(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $test = PsychometricTest::factory()->mbti()->create(['published_at' => now()]);
        $result = app(MbtiService::class)->score($test->sections, $this->answersFor($test->sections, 'INFP'));
        $mbti = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id, 'completed_at' => now(), 'result' => $result]);
        $iq = PsychometricAttempt::factory()->create(['completed_at' => now(), 'raw_score' => 22, 'section_scores' => [5, 6, 7, 4]]);
        Livewire::actingAs($admin)->test(Psychometrics::class)
            ->set('typeFilter', 'mbti')->assertSee('INFP')
            ->assertViewHas('attempts', fn ($rows) => $rows->modelKeys() === [$mbti->id])
            ->set('typeFilter', 'iq')->assertViewHas('attempts', fn ($rows) => $rows->modelKeys() === [$iq->id])
            ->call('resetResultFilters')->assertSet('typeFilter', '')
            ->assertViewHas('attempts', fn ($rows) => $rows->total() === 2);
        $path = tempnam(sys_get_temp_dir(), 'mbti-export-');
        try {
            app(PsychometricResultExportService::class)->write($admin, ['search' => '', 'position' => '', 'period' => '', 'type' => 'mbti'], $path);
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
            $this->assertContains('Tipe MBTI (X = seimbang)', $rows[0]);
            $this->assertNotContains('IQ (konversi aplikasi)', $rows[0]);
            $this->assertEquals(['INFP', 40, 40, 0, 100, 0, 100, 0, 100, 0, 100], array_slice($rows[1], 8, 11));
            $this->assertCount(count($rows[0]), $rows[1]);
            $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][19]);
        } finally {
            unlink($path);
        }
    }

    public function test_seeder_adds_both_test_types_without_overwriting_existing_packages(): void
    {
        $legacy = PsychometricTest::factory()->legacyMbti()->published()->create();
        $legacySections = $legacy->sections;
        $this->seed(PsychometricTestSeeder::class);
        $test = PsychometricTest::where('title', 'TES MBTI (7 pilihan)')->sole();
        $sections = $test->sections;
        $sections[0]['seconds'] = 600;
        $test->update(['sections' => $sections]);
        $this->seed(PsychometricTestSeeder::class);
        $this->assertDatabaseCount('psychometric_tests', 3);
        $this->assertSame(600, $test->fresh()->sections[0]['seconds']);
        $this->assertSame($legacySections, $legacy->fresh()->sections);
    }

    public function test_migration_preserves_existing_iq_data_and_refuses_to_drop_mbti_results(): void
    {
        $iq = PsychometricAttempt::factory()->create(['raw_score' => 22, 'answers' => [[1 => ['A']]]]);
        $migration = require database_path('migrations/2026_11_04_034223_add_mbti_to_psychometrics.php');
        $migration->down();
        $migration->up();
        $this->assertSame('iq', $iq->test->fresh()->type);
        $this->assertSame(22, $iq->fresh()->raw_score);
        $this->assertSame([[1 => ['A']]], $iq->fresh()->answers);
        PsychometricTest::factory()->mbti()->create();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function loginFor(PsychometricAttempt $attempt): void
    {
        $user = $attempt->application->user;
        $this->actingAs($user)->withSession(['portal_session_version' => $user->session_version]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<int, array<int, string>>>
     */
    private function answersFor(array $sections, string $type): array
    {
        $answers = [];
        foreach ($sections as $index => $section) {
            foreach ($section['questions'] as $number => $question) {
                if (isset($question['statement'])) {
                    foreach (str_split($question['dimension']) as $preference) {
                        if (str_contains($type, $preference)) {
                            $answers[$index][$number + 1] = [$question['preference'] === $preference ? 'A' : 'G'];
                        }
                    }

                    continue;
                }
                foreach ($question['preferences'] as $choice => $preference) {
                    if (str_contains($type, $preference)) {
                        $answers[$index][$number + 1] = [$choice];
                    }
                }
            }
        }

        return $answers;
    }
}
