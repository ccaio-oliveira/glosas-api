<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::where('clinic_id', $request->user()->clinic_id)->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'in:owner,biller,viewer'],
        ]);

        $temporaryPassword = Str::password(12);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'clinic_id' => $request->user()->clinic_id,
            'password' => Hash::make($temporaryPassword),
        ]);

        return response()->json([
            'user' => $user,
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        abort_if($user->clinic_id !== $request->user()->clinic_id, 404);

        $data = $request->validate([
            'role' => ['required', 'in:owner,biller,viewer'],
        ]);

        if ($user->isOwner() && $data['role'] !== 'owner' && $this->isLastOwner($user)) {
            abort(422, 'Esta é a única pessoa com papel de dono. Promova outra antes de alterar.');
        }

        $user->update($data);

        return $user;
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->clinic_id !== $request->user()->clinic_id, 404);
        abort_if($user->id === $request->user()->id, 422);

        if ($user->isOwner() && $this->isLastOwner($user)) {
            abort(422, 'Esta é a única pessoa com papel de dono. Promova outra antes de alterar.');
        }

        $user->delete();

        return response()->noContent();
    }

    private function isLastOwner(User $user): bool
    {
        return User::where('clinic_id', $user->clinic_id)
        ->where('role', 'owner')
        ->where('id', '!=', $user->id)
        ->doesntExist();
    }
}
