<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class TissUpload extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'clinic_id', 'uploaded_by_user_id', 'original_filename', 'path',
        'size_bytes', 'status', 'claims_count', 'denials_count', 'error_message'
    ];

    public function claims()
    {
        return $this->hasMany(Claim::class);
    }
}
