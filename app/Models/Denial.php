<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Denial extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['clinic_id', 'claim_item_id', 'category', 'reason_code', 'reason_description', 'amount', 'status', 'needs_ai_review', 'identified_at'];

    protected function casts(): array
    {
        return [
            'identified_at' => 'date',
            'needs_ai_review' => 'boolean',
        ];
    }

    public function claimItem()
    {
        return $this->belongsTo(ClaimItem::class);
    }

    public function appeals()
    {
        return $this->hasMany(Appeal::class);
    }
}
