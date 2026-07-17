<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    protected $fillable = ['clinic_id', 'category', 'source', 'message', 'context', 'severity', 'resolved', 'resolved_at', 'resolved_by_user_id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public static function log(
        string $category,
        string $message,
        ?string $source = null,
        array $context = [],
        string $severity = 'error',
        ?int $clinicId = null
    ): self {
        return static::create([
            'clinic_id' => $clinicId,
            'category' => $category,
            'source' => $source,
            'message' => $message,
            'context' => $context,
            'severity' => $severity,
        ]);
    }
}
