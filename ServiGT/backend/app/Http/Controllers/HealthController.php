<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Exception $e) {
            $dbStatus = 'unavailable';
            Log::warning('health.database_unavailable', [
                'correlation_id' => request()->attributes->get(CorrelationId::ATTRIBUTE),
                'exception' => $e::class,
            ]);
        }

        return response()->json([
            'status'   => 'ok',
            'app'      => 'ServiGT Guatemala',
            'backend'  => 'Laravel PHP',
            'database' => [
                'driver' => config('database.default'),
                'status' => $dbStatus,
            ],
            'correlation_id' => request()->attributes->get(CorrelationId::ATTRIBUTE),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
