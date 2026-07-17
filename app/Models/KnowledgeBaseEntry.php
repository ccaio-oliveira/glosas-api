<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeBaseEntry extends Model
{
    protected $fillable = ['type', 'payer_id', 'title', 'content'];

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }
}
