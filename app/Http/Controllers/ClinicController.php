<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function show(Request $request)
    {
        return $request->user()->clinic;
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:255'],
            'cro' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $clinic = $request->user()->clinic;
        $clinic->update($data);

        return $clinic;
    }

    public function updatePlan(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);

        $data = $request->validate([
            'plan' => ['required', 'in:starter,professional,enterprise'],
        ]);

        $clinic = $request->user()->clinic;
        $clinic->update(['current_plan' => $data['plan']]);

        return $clinic;
    }
}
