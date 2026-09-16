<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecruitmentApplicationFactory extends Factory
{
    protected $model = RecruitmentApplication::class;

    public function definition(): array
    {
        $user = User::factory()->create();

        return ['user_id' => $user->id, 'active_user_id' => $user->id, 'position_id' => Position::factory(), 'recruitment_period_id' => RecruitmentPeriod::factory()];
    }
}
