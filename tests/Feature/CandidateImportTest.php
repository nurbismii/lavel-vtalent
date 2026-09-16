<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Recruitment;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\CandidateImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class CandidateImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $admin->saveAppAuthenticationSecret('TESTSECRET');

        return $admin;
    }

    public function test_template_and_excel_import_create_private_hashed_accounts_ready_for_selection(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $csv = "\xEF\xBB\xBFnama,email,password,telepon\r\n\"Andi, Putra\",ANDI@example.com,UnikAndi12345,08123456789\r\nBudi,budi@example.com,UnikBudi12345,\r\n";
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->call('navigate', 'import')
            ->call('downloadCandidateTemplate')->assertFileDownloaded('template-import-kandidat.xlsx')
            ->set('importFile', $this->excelFile($csv));
        $temporaryPath = $page->get('importFile')->getRealPath();
        $page->assertSee('File sudah diunggah, akun belum disimpan.');
        $this->assertDatabaseCount('users', 1);
        $page->call('importCandidates')->assertHasNoErrors()->assertSee('2 akun kandidat berhasil diimport')->assertSet('importFile', null)
            ->assertSet('section', 'create')->assertSet('candidateMode', 'existing')->assertSee('Andi, Putra')->assertSee('budi@example.com');
        $this->assertFileDoesNotExist($temporaryPath);
        $user = User::where('email', 'andi@example.com')->sole();
        $this->assertTrue(Hash::check('UnikAndi12345', $user->password));
        $this->assertSame(Role::Candidate, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->temporary_password_expires_at->isFuture());
        $this->assertSame('08123456789', $user->profile->phone);
        $this->assertDatabaseCount('recruitment_applications', 0);
        $this->assertSame(2, AuditLog::where('action', 'account.imported')->count());
        $this->assertStringNotContainsString('UnikAndi12345', AuditLog::all()->toJson());
        $page->call('navigate', 'create')->set('candidateMode', 'existing')->set('candidateSearch', 'andi@example.com')->assertSee('Andi, Putra');
        $this->actingAs($user)->post('/login', ['email' => $user->email, 'password' => 'UnikAndi12345'])->assertRedirect(route('password.initial'));
    }

    public function test_invalid_rows_and_duplicates_are_reported_without_leaking_password_or_creating_accounts(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $csv = "nama,email,password,telepon\nAndi,andi@example.com,validPassword123,\nBudi,ANDI@example.com,short,invalidPhone\n,no-email,validPassword123,\n";
        $page = Livewire::actingAs($admin)->test(Recruitment::class)->set('importFile', $this->excelFile($csv));
        $path = $page->get('importFile')->getRealPath();
        $page->call('importCandidates')->assertHasErrors('importFile')->assertSee('Baris 3')->assertSee('Baris 4')->assertDontSee('validPassword123');
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_accounts_are_not_overwritten_and_valid_new_rows_are_not_partially_saved(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $password = $user->password;
        $csv = "nama,email,password,telepon\nBaru,new@example.com,validPassword123,\nGanti,existing@example.com,replacement12345,\n";
        $this->expectImportError($admin, $csv, 'email sudah terdaftar');
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_header_empty_file_wrong_columns_row_limit_and_optional_phone(): void
    {
        $admin = $this->admin();
        foreach ([
            '' => 'Header harus',
            "nama,email,password,role\n" => 'Header harus',
            "nama,email,password,telepon\n" => 'belum berisi',
            "nama,email,password,telepon\nAndi,andi@example.com,validPassword123,,tambahan\n" => 'jumlah kolom',
        ] as $csv => $expected) {
            $this->expectImportError($admin, $csv, $expected);
        }
        config(['candidate_import.max_rows' => 1]);
        $this->expectImportError($admin, "nama,email,password,telepon\nA,a@example.com,validPassword123,\nB,b@example.com,validPassword123,", 'Maksimal 1');
        $file = $this->excelFile("email,nama,telepon,password\nbudi@example.com,Budi,,validPassword123\n");
        $this->assertSame(1, app(CandidateImportService::class)->import($admin, $file->getRealPath()));
    }

    public function test_transaction_rolls_back_accounts_profiles_and_audits_when_later_row_fails(): void
    {
        $admin = $this->admin();
        $created = 0;
        User::creating(function () use (&$created) {
            if (++$created === 2) {
                throw ValidationException::withMessages(['importFile' => 'Simulasi gagal.']);
            }
        });
        try {
            $this->expectImportError($admin, "nama,email,password,telepon\nA,a@example.com,validPassword123,08123\nB,b@example.com,validPassword123,", 'Simulasi gagal');
            $this->assertSame(2, $created);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('candidate_profiles', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        } finally {
            User::flushEventListeners();
        }
    }

    public function test_candidate_cannot_access_import_or_template(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => Role::Candidate]))->test(Recruitment::class)->assertForbidden();
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test(Recruitment::class);
        $admin->update(['active' => false]);
        $page->call('downloadCandidateTemplate')->assertForbidden();
    }

    public function test_upload_rejects_missing_wrong_extension_and_oversized_file(): void
    {
        Storage::fake('private');
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)
            ->call('importCandidates')->assertHasErrors('importFile');
        $page->set('importFile', UploadedFile::fake()->createWithContent('data.csv', "nama,email,password,telepon\n"))
            ->call('importCandidates')->assertHasErrors('importFile');
        config(['candidate_import.max_kilobytes' => 1]);
        $page->set('importFile', $this->excelFile("nama,email,password,telepon\n"))
            ->call('importCandidates')->assertHasErrors('importFile');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_downloaded_template_contains_input_and_guide_sheets_with_text_formatting(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Recruitment::class)
            ->call('downloadCandidateTemplate')->assertFileDownloaded('template-import-kandidat.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'template-test-');
        file_put_contents($path, base64_decode($page->effects['download']['content']));
        $zip = new \ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertStringContainsString('name="Kandidat"', $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('name="Panduan"', $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('numFmtId="49"', $zip->getFromName('xl/styles.xml'));
            $this->assertStringContainsString('r="D101"', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->expectException(ValidationException::class);
            app(CandidateImportService::class)->import(auth()->user(), $path);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_excel_formula_and_corrupt_workbook_are_rejected_without_partial_import(): void
    {
        $admin = $this->admin();
        $this->expectImportError($admin, "nama,email,password,telepon\n\nA,a@example.com,validPassword123,\nB,b@example.com,=SUM(12345678),", 'Baris 4');
        $file = UploadedFile::fake()->createWithContent('broken.xlsx', 'not-an-excel-file');
        try {
            app(CandidateImportService::class)->import($admin, $file->getRealPath());
            $this->fail('File rusak seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak valid atau rusak', implode(' ', $exception->errors()['importFile']));
        }
        $this->assertDatabaseCount('users', 1);
    }

    private function expectImportError(User $admin, string $csv, string $expected): void
    {
        $file = $this->excelFile($csv);
        try {
            app(CandidateImportService::class)->import($admin, $file->getRealPath());
            $this->fail('Import seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($expected, implode(' ', $exception->errors()['importFile']));
        }
    }

    private function excelFile(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'candidate-test-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Kandidat');
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $writer->addRow(Row::fromValues($values));
        }
        fclose($stream);
        $writer->close();
        try {
            return UploadedFile::fake()->createWithContent('kandidat.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
