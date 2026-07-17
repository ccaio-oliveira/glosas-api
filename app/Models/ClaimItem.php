<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClaimItem extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['clinic_id', 'claim_id', 'procedure_code', 'description', 'billed_amount', 'paid_amount', 'denied_amount', 'denial_reason_code_id'];

    public function claim()
    {
        return $this->belongsTo(Claim::class);
    }

    public function denialReasonCode()
    {
        return $this->belongsTo(DenialReasonCode::class);
    }

    public function denial()
    {
        return $this->hasOne(Denial::class);
    }
}
