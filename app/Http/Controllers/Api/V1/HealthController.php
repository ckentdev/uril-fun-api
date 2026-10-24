<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Public liveness check. Deliberately minimal: no versions, config or
     * dependency details are exposed here.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ]);
    }
}
