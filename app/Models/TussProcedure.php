<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabela 22 do TUSS: catálogo oficial de procedimentos da ANS.
 * Global, sem `clinic_id` — mesma natureza de `DenialReasonCode`.
 */
class TussProcedure extends Model
{
    protected $fillable = ['code', 'description', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    /** O código já estava em vigência na data do atendimento? */
    public function wasValidOn(?string $date): bool
    {
        if ($date === null) {
            return true;
        }

        if ($this->valid_from && $date < $this->valid_from->toDateString()) {
            return false;
        }

        return ! ($this->valid_until && $date > $this->valid_until->toDateString());
    }
}
