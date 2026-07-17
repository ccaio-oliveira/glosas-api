<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payer extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['clinic_id', 'name', 'ans_registry_code', 'integration_type', 'config'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function claims()
    {
        return $this->hasMany(Claim::class);
    }

    public function knowledgeBaseEntries()
    {
        return $this->hasMany(KnowledgeBaseEntry::class);
    }
}
