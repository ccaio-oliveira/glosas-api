<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::define('operate', fn (User $user) => $user->canOperate());
        Gate::define('manage-clinic', fn (User $user) => $user->isOwner());
        Gate::define('manage-users', fn (User $user) => $user->isOwner());
        Gate::define('manage-billing', fn (User $user) => $user->isOwner());
    }
}
