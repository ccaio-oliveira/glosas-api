<?php

namespace App\Models\Concerns;

use App\Models\Clinic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToClinic
{
    protected static function bootBelongsToClinic(): void
    {
        // Sem exceção para o super_admin (achado M6): usuário autenticado só vê a
        // própria clínica. Sem clínica (super_admin), não vê nada — e não
        // `where(col, null)`, que vira `whereNull` e casaria linha órfã.
        // Jobs e comandos rodam sem Auth e removem o scope explicitamente.
        static::addGlobalScope('clinic', function (Builder $builder) {
            if (! Auth::check()) {
                return;
            }

            $clinicId = Auth::user()->clinic_id;

            $clinicId
                ? $builder->where($builder->getModel()->getTable() . '.clinic_id', $clinicId)
                : $builder->whereRaw('1 = 0');
        });

        static::creating(function ($model) {
            if (!$model->clinic_id && Auth::check()) {
                $model->clinic_id = Auth::user()->clinic_id
                    ?? throw new \LogicException('Registro de clínica criado por usuário sem clínica.');
            }
        });
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
