<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication
{
    use HasFactory,InteractsWithAppAuthentication,Notifiable;

    protected $attributes = ['role' => 'candidate', 'active' => true, 'must_change_password' => false, 'session_version' => 1];

    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'must_change_password', 'temporary_password_expires_at', 'session_version', 'remember_token'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => Role::class, 'active' => 'boolean', 'must_change_password' => 'boolean', 'temporary_password_expires_at' => 'datetime', 'session_version' => 'integer'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active && $this->role === Role::Admin && ! $this->must_change_password;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class);
    }
}
