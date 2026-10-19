<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class ClinicPayer extends Model
{
    use BelongsToClinic;

    protected $fillable = ['clinic_id', 'payer_id', 'integration_type', 'config'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    /**
     * Prazo contratual para recorrer de glosa deste convênio, em dias.
     * Vem do contrato da clínica — não existe padrão universal, então null
     * significa "não configurado", nunca um valor presumido.
     */
    public function appealDeadlineDays(): ?int
    {
        $days = $this->config['appeal_deadline_days'] ?? null;

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }
}
