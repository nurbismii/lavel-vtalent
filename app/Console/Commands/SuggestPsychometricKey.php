<?php

namespace App\Console\Commands;

use App\Services\PsychometricService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SuggestPsychometricKey extends Command
{
    protected $signature = 'psychometrics:suggest-key {test : ID paket draf dengan kunci kosong}';

    protected $description = 'Fill a compatible empty TES IQ draft with an unverified AI answer proposal for HR review';

    public function handle(PsychometricService $service): int
    {
        $id = (string) $this->argument('test');
        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('ID paket harus berupa bilangan positif.');

            return self::FAILURE;
        }
        try {
            $service->suggestKey((int) $id);
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }
        $this->info('Usulan kunci disimpan sebagai draf untuk ditinjau HR. Tes tidak diterbitkan.');

        return self::SUCCESS;
    }
}
