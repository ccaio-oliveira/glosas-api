<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Clinic extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'cnpj', 'current_plan', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function payers()
    {
        return $this->hasMany(Payer::class);
    }

    public function claims()
    {
        return $this->hasMany(Claim::class);
    }

    public function errorLogs()
    {
        return $this->hasMany(ErrorLog::class);
    }
}
