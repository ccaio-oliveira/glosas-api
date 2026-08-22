<?php

namespace App\Support;

class StatusLabels
{
    public const DENIAL = [
        'new' => 'Nova',
        'pending' => 'Pendente',
        'appealed' => 'Contestada',
        'recovered' => 'Recuperada',
        'rejected' => 'Negada',
    ];

    public const APPEAL = [
        'draft' => 'Rascunho',
        'submitted' => 'Enviado',
        'under_review' => 'Em análise',
        'accepted' => 'Recuperado',
        'rejected' => 'Negado',
    ];

    public static function denial(?string $s): string
    {
        return self::DENIAL[$s] ?? $s ?? '-';
    }

    public static function appeal(?string $s): string
    {
        return self::APPEAL[$s] ?? $s ?? '-';
    }
}
