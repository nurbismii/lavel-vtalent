<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\TechnicalAssessments;
use App\Models\AuditLog;
use App\Models\TechnicalAssessmentAttempt;
use App\Models\User;
use App\Services\TechnicalAssessmentResultExportService;
use App\Services\TechnicalAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TechnicalAssessmentResultExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_contains_assigned_questions_answers_materials_scores_and_assessor_review_for_both_forms(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $packages = app(TechnicalAssessmentService::class)->packages();
        $packages['A']['sections'][0]['questions'][0]['prompt'] = 'Soal versi penugasan awal';
        $packages['A']['assessor_material'] = [['text' => 'RAHASIA ASSESSOR']];
        $formA = TechnicalAssessmentAttempt::factory()->create([
            'package' => $packages['A'], 'deadline' => now()->subMinute(),
            'answers' => [0 => ['A01' => 'C'], 2 => ['S01' => 'A'], 3 => ['IPIP01' => '1']],
        ]);
        $formA->application->user->update(['name' => '=HYPERLINK("https://example.com")']);
        $formB = TechnicalAssessmentAttempt::factory()->create([
            'recruitment_application_id' => $formA->recruitment_application_id, 'form' => 'B', 'package' => $packages['B'], 'version' => $packages['B']['version'],
            'deadline' => now()->subMinute(), 'answers' => [0 => ['B01' => 'B', 'process' => "=1+1\nPenjelasan proses kandidat"], 1 => ['priority_1_priority' => '0', 'revised_decision' => 'Gunakan rekening master setelah verifikasi.']],
            'conditions' => ['technical_conditions' => 'Koneksi sempat terputus.'],
        ]);
        $service = app(TechnicalAssessmentService::class);
        $service->expire($formA);
        $service->expire($formB);
        $ratings = array_fill_keys(TechnicalAssessmentService::RATING_DIMENSIONS, 3);
        $ratings['Kontrol risiko'] = 0;
        $service->review($admin, $formB->id, ['status' => 'Kemampuan terdemonstrasi', 'notes' => 'Bukti proses telah diperiksa.', 'ratings' => $ratings]);
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $formA->recruitment_application_id, 'version' => 'belum-selesai']);
        TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);

        $sheets = $this->export($admin, $formA->application->user->email);

        $this->assertSame(['Ringkasan Form A', 'Soal Jawaban Form A', 'Ringkasan Form B', 'Soal Jawaban Form B'], array_keys($sheets));
        foreach (['A' => $formA, 'B' => $formB] as $form => $attempt) {
            $summary = $sheets['Ringkasan Form '.$form];
            $this->assertCount(2, $summary);
            $values = array_combine($summary[0], $summary[1]);
            $this->assertEquals($attempt->id, $values['ID hasil']);
            $this->assertSame('=HYPERLINK("https://example.com")', $values['Nama kandidat']);
            $this->assertSame($attempt->version, $values['Versi']);
            $this->assertEquals(count(array_merge(...array_values($attempt->answers))), $values['Soal dijawab']);
            $this->assertEquals(array_sum(array_map(fn (array $section): int => count($section['questions']), $attempt->sectionsForDisplay())), $values['Jumlah soal']);
        }
        $summaryA = array_combine($sheets['Ringkasan Form A'][0], $sheets['Ringkasan Form A'][1]);
        $this->assertEquals(1, $summaryA['Penalaran']);
        $this->assertEquals(0, $summaryA['SJT']);
        $this->assertEquals($formA->fresh()->scores['IPP pilot'], $summaryA['IPP pilot']);
        $this->assertSame('', $summaryA['E']);
        $summaryB = array_combine($sheets['Ringkasan Form B'][0], $sheets['Ringkasan Form B'][1]);
        $this->assertEquals(1, $summaryB['Penalaran objektif']);
        $this->assertEquals(0, $summaryB['Kontrol risiko (0–4)']);
        $this->assertSame('Kemampuan terdemonstrasi', $summaryB['Status verifikasi']);
        $this->assertSame('Bukti proses telah diperiksa.', $summaryB['Catatan assessor']);

        $answersA = collect(array_slice($sheets['Soal Jawaban Form A'], 1))->keyBy(fn (array $row): string => $row[5]);
        $this->assertSame('Soal versi penugasan awal', $answersA['A01'][7]);
        $this->assertSame('C · '.$packages['A']['sections'][0]['questions'][0]['options']['C'], $answersA['A01'][10]);
        $this->assertEquals(1, $answersA['A01'][11]);
        $this->assertSame('Belum dijawab', $answersA['A02'][10]);
        $this->assertEquals(0, $answersA['A02'][11]);
        $this->assertEquals(4, $answersA['S01'][12]);
        $this->assertStringContainsString('Dokumen A Permintaan Pembayaran', $answersA['D01'][8]);
        $this->assertStringContainsString('INV-381', $answersA['D01'][8]);
        $this->assertSame('1 · Sangat tidak sesuai', $answersA['IPIP01'][10]);
        $this->assertSame('', $answersA['IPIP01'][11]);
        $answersB = collect(array_slice($sheets['Soal Jawaban Form B'], 1))->keyBy(fn (array $row): string => $row[5]);
        $this->assertSame("=1+1\nPenjelasan proses kandidat", $answersB['process'][10]);
        $this->assertSame('', $answersB['process'][11]);
        $this->assertSame('0', $answersB['priority_1_priority'][10]);
        $this->assertStringContainsString('Pada pukul 14.05', $answersB['revised_decision'][8]);
        $this->assertSame('Koneksi sempat terputus.', $answersB['technical_conditions'][10]);
        $this->assertStringNotContainsString('RAHASIA ASSESSOR', json_encode($sheets, JSON_THROW_ON_ERROR));
        $this->assertSame(2, AuditLog::where('action', 'technical_assessment.results_exported')->sole()->metadata['count']);
    }

    public function test_candidate_export_includes_both_completed_forms_only_for_that_application(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $formA = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);
        $packageB = app(TechnicalAssessmentService::class)->packages()['B'];
        TechnicalAssessmentAttempt::factory()->create(['recruitment_application_id' => $formA->recruitment_application_id, 'form' => 'B', 'package' => $packageB, 'version' => $packageB['version'], 'completed_at' => now()]);
        TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);

        $sheets = $this->export($admin, applicationId: $formA->recruitment_application_id);

        foreach (['A', 'B'] as $form) {
            $this->assertCount(2, $sheets['Ringkasan Form '.$form]);
            $this->assertEquals($formA->recruitment_application_id, $sheets['Ringkasan Form '.$form][1][1]);
        }
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->set('resultSearch', 'pencarian-tidak-cocok')->call('selectAttempt', $formA->id)
            ->assertSee('Ekspor kandidat (Form A + B)')
            ->call('exportResults', $formA->recruitment_application_id)->assertFileDownloaded();
        $this->assertSame(2, AuditLog::where('action', 'technical_assessment.results_exported')->latest('id')->firstOrFail()->metadata['count']);
    }

    public function test_export_preserves_original_section_indexes_and_legacy_condition_answers(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $package = app(TechnicalAssessmentService::class)->packages()['B'];
        array_unshift($package['sections'], ['title' => 'Orientasi', 'material' => [], 'questions' => []]);
        $package['condition_questions'] = [];
        $package['sections'][] = ['title' => 'Kuesioner', 'kind' => 'questionnaire', 'questions' => [
            ['id' => 'legacy_condition', 'prompt' => 'Catatan kondisi versi lama?', 'type' => 'text'],
        ]];
        $package['sections'][1]['questions'][0]['material'] = [['heading' => 'Panduan soal', 'items' => ['Baca dokumen sebelum menjawab.']]];
        TechnicalAssessmentAttempt::factory()->create([
            'form' => 'B', 'version' => 'versi-lama', 'package' => $package, 'completed_at' => now(),
            'answers' => [1 => ['B01' => 'B'], 3 => ['probe_1' => 'Jawaban probe kandidat.'], 4 => ['legacy_condition' => 'Tidak ada gangguan.']],
        ]);

        $sheets = $this->export($admin);

        $answers = collect(array_slice($sheets['Soal Jawaban Form B'], 1))->keyBy(fn (array $row): string => $row[5]);
        $this->assertSame('B · '.$package['sections'][1]['questions'][0]['options']['B'], $answers['B01'][10]);
        $this->assertSame("Panduan soal\nBaca dokumen sebelum menjawab.", $answers['B01'][8]);
        $this->assertSame('Jawaban probe kandidat.', $answers['probe_1'][10]);
        $this->assertSame('Tidak ada gangguan.', $answers['legacy_condition'][10]);
        $this->assertSame('Catatan kondisi', $answers['legacy_condition'][4]);
        $this->assertCount($answers->count() + 1, $sheets['Soal Jawaban Form B']);
        $summary = array_combine($sheets['Ringkasan Form B'][0], $sheets['Ringkasan Form B'][1]);
        $this->assertEquals(2, $summary['Soal dijawab']);
    }

    public function test_export_ignores_purged_incomplete_and_other_forms_and_handles_no_matches(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $purged = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);
        $purged->application->update(['purged_at' => now()]);
        TechnicalAssessmentAttempt::factory()->create();
        TechnicalAssessmentAttempt::factory()->create(['form' => 'A2', 'completed_at' => now()]);

        $sheets = $this->export($admin);

        foreach (['A', 'B'] as $form) {
            $this->assertSame(['Tidak ada hasil tes selesai sesuai filter.'], $sheets['Ringkasan Form '.$form][1]);
            $this->assertCount(1, $sheets['Soal Jawaban Form '.$form]);
        }
        $completed = TechnicalAssessmentAttempt::factory()->create(['completed_at' => now()]);
        $sheets = $this->export($admin, 'tidak-ada-kandidat-cocok');
        $this->assertSame(['Tidak ada hasil tes selesai sesuai filter.'], $sheets['Ringkasan Form A'][1]);
        Livewire::actingAs($admin)->test(TechnicalAssessments::class)
            ->assertSee('Ekspor hasil (Excel)')->set('resultSearch', $completed->application->user->email)
            ->call('exportResults')->assertFileDownloaded();
        $this->assertSame(1, AuditLog::where('action', 'technical_assessment.results_exported')->latest('id')->firstOrFail()->metadata['count']);
    }

    public function test_export_rejects_candidates_inactive_admins_and_admins_requiring_password_change(): void
    {
        foreach ([['role' => Role::Candidate], ['role' => Role::Admin, 'active' => false], ['role' => Role::Admin, 'must_change_password' => true]] as $attributes) {
            $actor = User::factory()->create($attributes);
            try {
                app(TechnicalAssessmentResultExportService::class)->write($actor, '', 'php://output');
                $this->fail('Unauthorized export should be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            Livewire::actingAs($actor)->test(TechnicalAssessments::class)->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'technical_assessment.results_exported']);
    }

    /** @return array<string, array<int, array<int, mixed>>> */
    private function export(User $admin, string $search = '', ?int $applicationId = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'technical-export-');
        try {
            app(TechnicalAssessmentResultExportService::class)->write($admin, $search, $path, $applicationId);
            $reader = new Reader;
            $reader->open($path);
            $sheets = [];
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $sheets[$sheet->getName()][] = $row->toArray();
                    }
                }
            } finally {
                $reader->close();
            }
            $zip = new \ZipArchive;
            $zip->open($path);
            try {
                foreach (range(1, 4) as $number) {
                    $xml = $zip->getFromName('xl/worksheets/sheet'.$number.'.xml');
                    $this->assertDoesNotMatchRegularExpression('/<f(?:\s|>)/', $xml);
                    $this->assertStringContainsString('autoFilter', $xml);
                    $this->assertStringContainsString('pane', $xml);
                }
            } finally {
                $zip->close();
            }

            return $sheets;
        } finally {
            unlink($path);
        }
    }
}
