<?php

namespace Database\Factories;

use App\Models\EmailDelivery;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailDeliveryFactory extends Factory
{
    protected $model = EmailDelivery::class;

    public function definition(): array
    {
        return ['submission_id' => Submission::factory(), 'event' => 'revision', 'status' => 'pending'];
    }
}
