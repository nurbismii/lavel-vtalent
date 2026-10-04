<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Psychometrics;
use App\Models\AuditLog;
use App\Models\PsychometricAttempt;
use App\Models\User;
use App\Services\PsychometricResultExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PsychometricResultExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_filters_completed_results_and_preserves_safe_text_and_numeric_scores(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $attempt = PsychometricAttempt::factory()->create(['completed_at' => now(), 'raw_score' => 22, 'section_scores' => [5, 6, 7, 4]]);
        $attempt->application->user->update(['name' => '=HYPERLINK("https://example.com")']);
        PsychometricAttempt::factory()->create(['recruitment_application_id' => $attempt->recruitment_application_id]);
        PsychometricAttempt::factory()->create(['completed_at' => now(), 'raw_score' => 0]);
        $filters = ['search' => $attempt->application->user->email, 'position' => (string) $attempt->application->position_id, 'period' => (string) $attempt->application->recruitment_period_id];
        $path = tempnam(sys_get_temp_dir(), 'psych-export-');
        try {
            app(PsychometricResultExportService::class)->write($admin, $filters, $path);
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
            $this->assertEquals($attempt->id, $rows[1][0]);
            $this->assertSame('=HYPERLINK("https://example.com")', $rows[1][2]);
            $this->assertEquals([5, 6, 7, 4, 22, 50, 100], array_slice($rows[1], 8, 7));
            $this->assertSame($attempt->iqCategory(), $rows[1][15]);
            $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][16]);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertStringContainsString('autoFilter', $xml);
            $this->assertStringContainsString('pane', $xml);
            $this->assertSame(1, AuditLog::where('action', 'psychometric.results_exported')->firstOrFail()->metadata['count']);
        } finally {
            unlink($path);
        }
        Livewire::actingAs($admin)->test(Psychometrics::class)
            ->set('resultSearch', $filters['search'])->set('positionFilter', $filters['position'])->set('periodFilter', $filters['period'])
            ->call('exportResults')->assertFileDownloaded();
    }

    public function test_export_separates_packages_and_handles_empty_results(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        PsychometricAttempt::factory()->count(2)->create(['completed_at' => now(), 'raw_score' => 0, 'section_scores' => [0, 0, 0, 0]]);
        $path = tempnam(sys_get_temp_dir(), 'psych-export-');
        try {
            $service = app(PsychometricResultExportService::class);
            $service->write($admin, ['search' => '', 'position' => '', 'period' => ''], $path);
            $reader = new Reader;
            $reader->open($path);
            $names = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
            $reader->close();
            $this->assertCount(2, array_unique($names));
            $service->write($admin, ['search' => 'no-matching-candidate', 'position' => '', 'period' => ''], $path);
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $this->assertSame(['Tidak ada hasil tes selesai sesuai filter.'], $row->toArray());
                }
            }
            $reader->close();
        } finally {
            unlink($path);
        }
    }

    public function test_export_rejects_candidates_inactive_admins_and_password_change_required(): void
    {
        foreach ([['role' => Role::Candidate], ['role' => Role::Admin, 'active' => false], ['role' => Role::Admin, 'must_change_password' => true]] as $attributes) {
            $actor = User::factory()->create($attributes);
            try {
                app(PsychometricResultExportService::class)->write($actor, ['search' => '', 'position' => '', 'period' => ''], 'php://output');
                $this->fail('Unauthorized export should be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            Livewire::actingAs($actor)->test(Psychometrics::class)->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'psychometric.results_exported']);
    }
}
