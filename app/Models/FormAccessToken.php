<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormAccessToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    protected $hidden = ['token_hash'];
}
