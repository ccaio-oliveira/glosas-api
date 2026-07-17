<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Claim extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['clinic_id', 'payer_id', 'claim_number', 'patient_name', 'xml_path', 'total_amount', 'status'];

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }

    public function items()
    {
        return $this->hasMany(ClaimItem::class);
    }
}
