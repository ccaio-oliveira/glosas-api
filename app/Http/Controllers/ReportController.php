<?php

namespace App\Http\Controllers;

use App\Services\Reports\RecoveryReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function recovery(Request $request)
    {
        return $this->build($request)->data();
    }

    public function recoveryPdf(Request $request)
    {
        $data = $this->build($request)->data();

        $pdf = Pdf::loadView('pdf.report', [
            'clinic' => $request->user()->clinic,
            'report' => $data,
            'generatedAt' => now(),
        ])->setPaper('a4');

        return $pdf->download('relatorio-recuperacao-'.now()->format('Y-m-d').'.pdf');
    }

    private function build(Request $request): RecoveryReport
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return new RecoveryReport($data['from'] ?? null, $data['to'] ?? null);
    }
}
