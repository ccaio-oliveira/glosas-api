<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToClinic;

    public const UPDATED_AT = null;

    protected $fillable = [
        'clinic_id', 'user_id', 'user_name', 'action', 'auditable_type', 'auditable_id',
        'denial_id', 'summary', 'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(
        string $action,
        Model $auditable,
        string $summary,
        ?array $changes = null,
        ?int $denialId = null,
        ?int $clinicId = null,
    ): self {
        $user = auth()->user();

        return static::create([
            'clinic_id' => $clinicId ?? $auditable->clinic_id ?? $user?->clinic_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Linha de comando',
            'action' => $action,
            'auditable_type' => class_basename($auditable),
            'auditable_id' => $auditable->id,
            'denial_id' => $denialId,
            'summary' => $summary,
            'changes' => $changes,
        ]);
    }
}
