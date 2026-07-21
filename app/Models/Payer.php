<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'ans_registry_code'];

    public function claims()
    {
        return $this->hasMany(Claim::class);
    }

    public function clinicPayers()
    {
        return $this->hasMany(ClinicPayer::class);
    }
}
