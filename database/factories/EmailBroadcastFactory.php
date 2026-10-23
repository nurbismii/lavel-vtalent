<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\EmailBroadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EmailBroadcast> */
class EmailBroadcastFactory extends Factory
{
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->state(['role' => Role::Admin]),
            'submission_key' => (string) Str::uuid(),
            'subject' => fake()->sentence(),
            'body' => '<p>'.fake()->sentence().'</p>',
        ];
    }
}
