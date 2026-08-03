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
        ]);

        $file = $request->file('file');
        $path = $file->store('tiss');

        $upload = TissUpload::create([
            'clinic_id' => $request->user()->clinic_id,
            'uploaded_by_user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
        ]);

        ProcessTissUploadJob::dispatch($upload->id);

        return response()->json($upload, 201);
    }
}
