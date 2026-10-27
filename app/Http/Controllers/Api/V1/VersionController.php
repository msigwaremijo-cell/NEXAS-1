<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class VersionController extends Controller
{
    /**
     * Report the running application, framework and database versions.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'application' => config('app.name'),
            'api_version' => 'v1',
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'database_version' => $this->databaseVersion(),
        ]);
    }

    private function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('select version() as version');

            return $row?->version;
        } catch (Throwable) {
            return null;
        }
    }
}
