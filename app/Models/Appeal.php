<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appeal extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['clinic_id', 'denial_id', 'ai_generated_text', 'generation_source', 'appeal_template_id', 'document_path', 'status', 'submission_channel', 'submitted_by_user_id', 'submitted_at', 'responded_at'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function denial()
    {
        return $this->belongsTo(Denial::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
