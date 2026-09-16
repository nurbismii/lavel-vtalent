<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        return $user->active && ! $user->must_change_password && ($user->role === Role::Admin || $submission->application->user_id === $user->id);
    }

    public function update(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission) && $user->role === Role::Candidate && $submission->editable();
    }
}
