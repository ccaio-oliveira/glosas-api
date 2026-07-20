<?php

namespace App\Http\Controllers;

use App\Models\Payer;
use Illuminate\Http\Request;

class PayerController extends Controller
{
    public function index(Request $request)
    {
        return Payer::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ans_registry_code' => ['nullable', 'string', 'max:255'],
            'integration_type' => ['required', 'in:manual,tiss_webservice'],
        ]);

        return Payer::create($data);
    }

    public function show(Payer $payer)
    {
        return $payer;
    }

    public function update(Request $request, Payer $payer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ans_registry_code' => ['nullable', 'string', 'max:255'],
            'integration_type' => ['required', 'in:manual,tiss_webservice'],
        ]);

        $payer->update($data);

        return $payer;
    }

    public function destroy(Payer $payer)
    {
        $payer->delete();

        return response()->noContent();
    }
}
