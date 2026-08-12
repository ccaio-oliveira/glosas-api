<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppealTemplate extends Model
{
    protected $fillable = ['clinic_id', 'name', 'scope', 'denial_reason_code', 'tiss_group', 'category', 'body', 'legal_basis', 'required_attachments', 'requires_clinical_input', 'is_active'];

    protected function casts(): array
    {
        return [
            'required_attachments' => 'array',
            'requires_clinical_input' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Peso de especificidade: código exato ganha de grupo, que ganha de categoria */
    public function specificity(): int
    {
        return match ($this->scope) {
            'code' => 3,
            'group' => 2,
            default => 1,
        };
    }
}
