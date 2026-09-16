<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePortalAdmin extends Command
{
    protected $signature = 'portal:admin';

    protected $description = 'Membuat administrator portal tanpa kredensial bawaan';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Nama admin'), 'email' => strtolower(trim($this->ask('Email admin') ?? '')), 'password' => $this->secret('Password (minimal 12 karakter)')];
        $validator = Validator::make($data, ['name' => 'required|max:255', 'email' => 'required|email|unique:users,email', 'password' => 'required|min:12']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create([...$data, 'role' => Role::Admin]);
        $this->info('Admin dibuat. Masuk melalui /admin/login dan aktifkan MFA.');

        return self::SUCCESS;
    }
}
