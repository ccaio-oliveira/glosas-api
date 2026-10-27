<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sem Gate::before para o super_admin (achado M6): ele não opera clínica.
        // O painel dele é protegido pelo middleware `super_admin`, não por Gate.
        Gate::define('operate', fn (User $user) => $user->canOperate());
        Gate::define('manage-clinic', fn (User $user) => $user->isOwner());
        Gate::define('manage-users', fn (User $user) => $user->isOwner());
        Gate::define('manage-billing', fn (User $user) => $user->isOwner());

        // Força bruta no login: 5/min por e-mail+IP, e 20/min por IP para quem
        // varre vários e-mails a partir da mesma origem.
        RateLimiter::for('login', function (Request $request) {
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'message' => 'Muitas tentativas de login. Tente novamente em '.$headers['Retry-After'].' segundos.',
            ], 429, $headers);

            // O throttle roda antes da validação: e-mail pode chegar como array.
            $email = $request->input('email');
            $email = is_string($email) ? Str::transliterate(Str::lower($email)) : '';

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip())->response($tooMany),
                Limit::perMinute(20)->by('login-ip:'.$request->ip())->response($tooMany),
            ];
        });
    }
}
