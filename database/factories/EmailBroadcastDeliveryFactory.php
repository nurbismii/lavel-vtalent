<?php

namespace Database\Factories;

use App\Models\EmailBroadcast;
use App\Models\EmailBroadcastDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailBroadcastDelivery> */
class EmailBroadcastDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_broadcast_id' => EmailBroadcast::factory(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
