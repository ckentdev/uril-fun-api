<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function __construct(private IntegrationRegistry $registry) {}

    /** Authenticated only: reveals configuration state (never values). */
    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registry->snapshot($request->user())]);
    }

    public function test(Request $request, string $integration): JsonResponse
    {
        abort_unless(in_array($integration, $this->registry->keys(), true), 404);

        return response()->json(['data' => $this->registry->test($integration, $request->user())]);
    }
}
