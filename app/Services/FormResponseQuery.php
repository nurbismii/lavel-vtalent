<?php

namespace App\Services;

use App\Models\FormResponse;
use Illuminate\Database\Eloquent\Builder;

class FormResponseQuery
{
    public function filtered(array $filters): Builder
    {
        return FormResponse::query()
            ->when($filters['search'] ?? '', fn ($q, $value) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$value.'%')->orWhere('email', 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? '', fn ($q, $value) => $q->where('status', $value))
            ->when(($filters['linked'] ?? '') === 'linked', fn ($q) => $q->whereNotNull('user_id'))
            ->when(($filters['linked'] ?? '') === 'unlinked', fn ($q) => $q->whereNull('user_id'))
            ->when($filters['intake'] ?? '', fn ($q, $value) => $q->where('form_intake_id', $value))
            ->when($filters['position'] ?? '', fn ($q, $value) => $q->whereHas('intake', fn ($q) => $q->where('position_id', $value)));
    }

    public function eligible(array $filters): Builder
    {
        return $this->filtered($filters)->where('status', 'submitted')->whereNotNull('submitted_at')
            ->whereNotNull('email_verified_at')->whereNull('user_id')->whereDoesntHave('accountDispatch');
    }
}
