<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\EmailDelivery;
use App\Models\UploadedFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

class PortalHealth extends Command
{
    protected $signature = 'portal:health';

    protected $description = 'Memeriksa kesiapan runtime portal tanpa menampilkan kredensial';

    public function handle(): int
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['Database'] = true;
        } catch (\Throwable) {
            $checks['Database'] = false;
        }
        try {
            $path = 'health/'.bin2hex(random_bytes(8));
            Storage::disk('private')->put($path, 'health');
            Storage::disk('private')->delete($path);
            $checks['Storage privat'] = true;
        } catch (\Throwable) {
            $checks['Storage privat'] = false;
        }
        $binary = (new ExecutableFinder)->find(config('submissions.scanner_binary'));
        $checks['ClamAV tersedia'] = $binary !== null;
        $checks['Queue asynchronous'] = config('queue.default') !== 'sync';
        $checks['Mailer produksi'] = ! in_array(config('mail.default'), ['log', 'array'], true);
        $checks['Kontak privasi ditetapkan'] = (bool) AppSetting::valueFor('privacy_contact');
        $checks['PHP upload >= 25 MB'] = $this->bytes(ini_get('upload_max_filesize')) >= 25 * 1048576;
        $checks['PHP post >= 30 MB'] = $this->bytes(ini_get('post_max_size')) >= 30 * 1048576;
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? 'OK   ' : 'CEK  ').$name);
        }
        $this->line('Scan tertunda: '.UploadedFile::where('scan_status', 'pending')->count());
        $this->line('Email gagal: '.EmailDelivery::where('status', 'failed')->count());
        $this->line('Worker aktif, koneksi SMTP, database antivirus, backup/restore, dan HTTPS perlu diuji di lingkungan deployment.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1073741824,'m' => $number * 1048576,'k' => $number * 1024,default => $number
        };
    }
}
