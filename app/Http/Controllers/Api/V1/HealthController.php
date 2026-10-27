<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Liveness/readiness probe for the API surface.
     */
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseStatus();

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
            ],
            'timestamp' => now()->toIso8601String(),
        ], $database === 'ok' ? 200 : 503);
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'unreachable';
        }
    }
}
