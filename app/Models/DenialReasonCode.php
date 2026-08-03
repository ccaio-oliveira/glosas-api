<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DenialReasonCode extends Model
{
    protected $fillable = ['code', 'description', 'category', 'tiss_group', 'tiss_group_label'];

    public function claimItems()
    {
        return $this->hasMany(ClaimItem::class);
    }
}
