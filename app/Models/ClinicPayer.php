<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class ClinicPayer extends Model
{
    use BelongsToClinic;

    protected $fillable = ['clinic_id', 'payer_id', 'integration_type', 'config'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }
}
