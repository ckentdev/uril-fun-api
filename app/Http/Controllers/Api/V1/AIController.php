<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function __construct(private AIService $ai) {}

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:'.config('ai.max_input_chars')],
            'system' => ['nullable', 'string', 'max:1000'],
        ]);

        $r = $this->ai->generate($data['prompt'], $data['system'] ?? null, $request->user()->id);

        return response()->json(['data' => [
            'content' => $r->content,
            'provider' => $r->provider,
            'model' => $r->model,
            'usage' => ['prompt_tokens' => $r->promptTokens, 'completion_tokens' => $r->completionTokens],
            'latency_ms' => $r->latencyMs,
        ]]);
    }

    /** Configuration state only. Never returns keys, prompts or base URLs. */
    public function status(): JsonResponse
    {
        $p = $this->ai->provider();

        return response()->json(['data' => [
            'provider' => $p->name(),
            'model' => $p->model(),
            'configured' => $p->isConfigured(),
            'is_mock' => $p->name() === 'mock',
            'max_input_chars' => (int) config('ai.max_input_chars'),
            'history_limit' => (int) config('ai.history_limit'),
        ]]);
    }
}
