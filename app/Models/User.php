<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $fillable = ['name', 'email', 'password', 'clinic_id', 'role'];

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isBiller(): bool
    {
        return $this->role === 'biller';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
}
