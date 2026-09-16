<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTissUploadJob;
use App\Models\TissUpload;
use Illuminate\Http\Request;

class TissUploadController extends Controller
{
    public function index()
    {
        return TissUpload::latest()->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimetypes:text/xml,application/xml,text/plain'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');

        // Hash do conteúdo, não do nome: renomear o arquivo não engana a checagem,
        // e o faturista frequentemente renomeia ao baixar de novo do portal.
        $hash = hash_file('sha256', $file->getRealPath());

        // Só bloqueia contra upload que deu certo — reenviar depois de falha é
        // esperado. A consulta já é filtrada pela clínica pelo global scope, então
        // o mesmo arquivo em outra clínica não interfere.
        if (! $request->boolean('force')) {
            $duplicate = TissUpload::where('content_hash', $hash)
                ->where('status', 'processed')
                ->latest()
                ->first();

            if ($duplicate) {
                return response()->json([
                    'message' => 'Este mesmo arquivo já foi processado.',
                    'duplicate' => [
                        'id' => $duplicate->id,
                        'original_filename' => $duplicate->original_filename,
                        'processed_at' => $duplicate->updated_at->toIso8601String(),
                        'claims_count' => $duplicate->claims_count,
                        'denials_count' => $duplicate->denials_count,
                    ],
                ], 409);
            }
        }

        $path = $file->store('tiss');

        $upload = TissUpload::create([
            'clinic_id' => $request->user()->clinic_id,
            'uploaded_by_user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'content_hash' => $hash,
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
        ]);

        ProcessTissUploadJob::dispatch($upload->id);

        return response()->json($upload, 201);
    }
}
