<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'hr.vtalent@vdnisite.com'],
            [
                'name' => 'HR VTalent',
                'password' => 'virtue@2026!',
                'role' => Role::Admin,
                'active' => true,
                'must_change_password' => false,
            ],
        );
    }
}
