<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json($this->presentUser($request->user()));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($request->user() ? $this->presentUser($request->user()) : null);
    }

    private function presentUser($user): array
    {
        return $user->load('clinic')->toArray() + [
            'permissions' => [
                'operate' => Gate::forUser($user)->allows('operate'),
                'manage_clinic' => Gate::forUser($user)->allows('manage-clinic'),
                'manage_users' => Gate::forUser($user)->allows('manage-users'),
                'manage_billing' => Gate::forUser($user)->allows('manage-billing'),
            ],
        ];
    }
}
