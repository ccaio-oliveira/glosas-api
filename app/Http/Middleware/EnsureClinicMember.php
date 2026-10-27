<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas operacionais da clínica: só quem pertence a uma clínica.
 * O super_admin (clinic_id nulo) fica de fora — ele administra a SaaS em /admin,
 * não lê glosa nem nome de paciente de clínica nenhuma (achado M6).
 */
class EnsureClinicMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user || ! $user->clinic_id || $user->isSuperAdmin(), 403);

        return $next($request);
    }
}
