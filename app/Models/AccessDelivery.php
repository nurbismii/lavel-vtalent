<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
