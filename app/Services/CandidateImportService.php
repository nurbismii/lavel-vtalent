<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class CandidateImportService
{
    public const HEADERS = ['nama', 'email', 'password', 'telepon'];

    public function writeTemplate(string $path): void
    {
        $options = new Options;
        $options->setColumnWidth(32, 1, 3);
        $options->setColumnWidth(42, 2);
        $options->setColumnWidth(26, 4);
        $writer = new Writer($options);
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Kandidat');
            $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('415E9E');
            $writer->addRow(Row::fromValues(self::HEADERS, $header));
            $text = (new Style)->setFormat('@');
            for ($i = 0; $i < config('candidate_import.max_rows'); $i++) {
                // Explicit string cells retain text formatting on otherwise empty rows.
                $writer->addRow(new Row(array_map(fn () => new StringCell('', $text), self::HEADERS)));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Panduan');
            $writer->getCurrentSheet()->setColumnWidth(95, 2);
            $writer->addRow(Row::fromValues(['Kolom', 'Petunjuk'], $header));
            foreach ([
                ['Pengisian', 'Isi sheet Kandidat mulai baris 2. Jangan ubah header. Simpan sebagai Excel Workbook (.xlsx).'],
                ['nama', 'Wajib, maksimal 255 karakter.'],
                ['email', 'Wajib, email valid yang belum terdaftar dan tidak duplikat.'],
                ['password', 'Wajib, minimal 12 karakter dan maksimal 72 byte. Berbeda untuk setiap kandidat; wajib diganti saat login pertama.'],
                ['telepon', 'Opsional, maksimal 30 karakter. Sel sudah berformat teks agar angka 0 di depan tetap ada.'],
                ['Batas', 'Maksimal '.config('candidate_import.max_rows').' kandidat dan '.(config('candidate_import.max_kilobytes') / 1024).' MB.'],
                ['Catatan', 'Gunakan teks biasa, bukan rumus. Akun lama tidak ditimpa. Jika ada kesalahan, seluruh import dibatalkan.'],
            ] as $row) {
                $writer->addRow(Row::fromValues($row, (new Style)->setShouldWrapText()));
            }
        } finally {
            $writer->close();
        }
    }

    private function readRows(string $path): \Generator
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('File Excel tidak valid atau rusak. Gunakan template .xlsx.');
        }
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
            }
            if ($zip->numFiles > 1000 || $size > 20 * 1024 * 1024) {
                $this->fail('Isi file Excel terlalu besar. Salin data ke template baru.');
            }
        } finally {
            $zip->close();
        }
        $options = new \OpenSpout\Reader\XLSX\Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Kandidat') {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number > 10000) {
                        $this->fail('Terlalu banyak baris kosong. Salin data ke template baru.');
                    }
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        if (! $cell instanceof StringCell && ! $cell instanceof EmptyCell) {
                            $this->fail("Baris $number: gunakan teks biasa, bukan angka, tanggal, atau rumus. Isi ulang pada sel template berformat teks.");
                        }
                        $values[] = $cell->getValue() ?? '';
                    }
                    while (count($values) > count(self::HEADERS) && end($values) === '') {
                        array_pop($values);
                    }
                    yield $number => $values;
                }

                return;
            }
            $this->fail('Sheet Kandidat tidak ditemukan. Gunakan template import Excel.');
        } catch (OpenSpoutException|\ValueError|\TypeError) {
            $this->fail('File Excel tidak dapat dibaca. Simpan ulang menggunakan template .xlsx.');
        } finally {
            $reader->close();
        }
    }

    public function import(User $actor, string $path): int
    {
        $currentActor = $actor->fresh();
        abort_unless($currentActor?->active && $currentActor->role === Role::Admin, 403);
        if (filesize($path) > config('candidate_import.max_kilobytes') * 1024) {
            $this->fail('Ukuran file melebihi batas import.');
        }
        $iterator = $this->readRows($path);
        try {
            $iterator->rewind();
            $headers = array_map(fn ($value) => Str::lower(trim($value ?? '')), $iterator->current() ?? []);
            if (count($headers) !== count(self::HEADERS) || array_diff(self::HEADERS, $headers)) {
                $this->fail('Header harus berisi nama, email, password, telepon tanpa kolom tambahan atau duplikat.');
            }
            $rows = [];
            $errors = [];
            $emails = [];
            $iterator->next();
            while ($iterator->valid()) {
                $record = $iterator->key();
                $values = $iterator->current();
                $iterator->next();
                if (count(array_filter($values, fn ($value) => trim($value ?? '') !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= config('candidate_import.max_rows')) {
                    $this->fail('Maksimal '.config('candidate_import.max_rows').' kandidat per file.');
                }
                $values = array_pad($values, count($headers), '');
                if (count($values) !== count($headers)) {
                    $errors[] = "Baris $record: jumlah kolom tidak sesuai template.";
                } else {
                    $data = array_combine($headers, array_map(fn ($value) => $value ?? '', $values));
                    $data['nama'] = trim($data['nama']);
                    $data['email'] = Str::lower(trim($data['email']));
                    $data['telepon'] = trim($data['telepon']);
                    $validator = Validator::make($data, [
                        'nama' => 'required|string|max:255',
                        'email' => 'required|email|max:255',
                        'password' => 'required|string|min:12',
                        'telepon' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9() .-]+$/'],
                    ]);
                    $validator->fails();
                    foreach (array_keys($validator->failed()) as $field) {
                        $errors[] = "Baris $record: kolom $field tidak valid. Periksa aturan pengisian template.";
                    }
                    if (! mb_check_encoding(implode('', $values), 'UTF-8') || str_contains(implode('', $values), "\0")) {
                        $errors[] = "Baris $record: gunakan teks tanpa karakter kontrol null.";
                    }
                    if (strlen($data['password']) > 72) {
                        $errors[] = "Baris $record: password maksimal 72 byte.";
                    }
                    if (isset($emails[$data['email']])) {
                        $errors[] = "Baris $record: email duplikat dengan baris {$emails[$data['email']]}.";
                    }
                    $emails[$data['email']] = $record;
                    $rows[] = $data;
                }
                if (count($errors) >= 20) {
                    break;
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(['importFile' => $errors]);
            }
            if (! $rows) {
                $this->fail('File belum berisi data kandidat. Isi baris di bawah header.');
            }
            $existing = User::whereIn(DB::raw('LOWER(email)'), array_keys($emails))->pluck('email');
            foreach ($existing as $email) {
                $errors[] = 'Baris '.$emails[Str::lower($email)].': email sudah terdaftar. Akun lama tidak diubah; hapus baris ini dari file.';
            }
            if ($errors) {
                throw ValidationException::withMessages(['importFile' => array_slice($errors, 0, 20)]);
            }

            try {
                return DB::transaction(function () use ($rows, $actor) {
                    foreach ($rows as $row) {
                        $user = User::create([
                            'name' => $row['nama'], 'email' => $row['email'], 'password' => Hash::make($row['password']),
                            'role' => Role::Candidate, 'active' => true, 'must_change_password' => true,
                            'temporary_password_expires_at' => now()->addHours(AppSetting::valueFor('temporary_password_hours')),
                        ]);
                        if ($row['telepon'] !== '') {
                            $user->profile()->create(['phone' => $row['telepon']]);
                        }
                        AuditLog::record('account.imported', $user, $actor);
                    }

                    return count($rows);
                });
            } catch (UniqueConstraintViolationException) {
                $this->fail('Ada email yang baru saja didaftarkan oleh proses lain. Tidak ada kandidat diimport; periksa ulang file.');
            }
        } finally {
            unset($iterator);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['importFile' => $message]);
    }
}
