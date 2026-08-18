<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErrorLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ErrorLog::with('clinic:id,name')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('severity')){
            $query->where('severity', $request->query('category'));
        }

        if ($request->query('resolved') !== null && $request->query('resolved') !== '') {
            $query->where('resolved', $request->boolean('resolved'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                ->orWhere('source', 'like', "%{$search}%");
            });
        }

        return $query->paginate(50);
    }

    public function summary()
    {
        $bySeverity = ErrorLog::where('resolved', false)
        ->select('severity', DB::raw('count(*) as total'))
        ->groupBy('severity')
        ->pluck('total', 'severity');

        $byCategory = ErrorLog::where('resolved', false)
        ->select('category', DB::raw('count(*) as total'))
        ->groupBy('category')
        ->orderByDesc('total')
        ->get();

        $recurring = ErrorLog::where('resolved', false)
        ->select('message', 'category', 'severity', DB::raw('count(*) as total', DB::raw('max(created_at) as last_seen')))
        ->groupBy('message', 'category', 'severity')
        ->having('total', '>', 1)
        ->orderByDesc('total')
        ->limit(10)
        ->get();

        return [
            'unresolved_total' => (int) $bySeverity->sum(),
            'by_severity' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'error' => (int) ($bySeverity['warning'] ?? 0),
                'warning' => (int) ($bySeverity['warning'] ?? 0),
                'info' => (int) ($bySeverity['info'] ?? 0),
            ],
            'by_category' => $byCategory,
            'recurring' => $recurring,
            'last_24h' => ErrorLog::where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    public function resolve(Request $request, ErrorLog $errorLog)
    {
        $errorLog->update([
            'resolved' => true,
            'resolved_at' => now(),
            'resolved_by_user_id' => $request->user()->id,
        ]);

        return $errorLog->load('clinic:id,name');
    }

    public function unresolve(ErrorLog $errorLog)
    {
        $errorLog->update(['resolved', false, 'resolved_at' => null, 'resolved_by_user_id' => null]);

        return $errorLog->load('clinic:id,name');
    }

    public function resolveGroup(Request $request)
    {
        $data = $request->validate(['message' => 'required', 'string']);

        $affected = ErrorLog::where('message', $data['message'])
        ->where('resolved', false)
        ->update([
            'resolved' => true,
            'resolved_at' => now(),
            'resolved_by_user_id' => $request->user()->id,
        ]);

        return ['resolved' => $affected];
    }
}
