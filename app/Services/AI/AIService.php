<?php

namespace App\Services\AI;

use App\Models\AiKnowledge;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function __construct(private AIProviderInterface $provider) {}

    public function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     *
     * @throws AIException
     */
    public function chat(array $messages, ?string $system = null, ?int $userId = null): AIResponse
    {
        $limit = max(1, (int) config('ai.history_limit'));
        $messages = array_slice($messages, -$limit);

        // The user's saved knowledge wins; AI_KNOWLEDGE is the server-wide fallback.
        $knowledge = $userId ? AiKnowledge::where('user_id', $userId)->value('content') : null;

        $request = new AIRequest(
            $messages,
            $system ?: config('ai.system_prompt'),
            filled($knowledge) ? $knowledge : config('ai.knowledge'),
        );

        $start = hrtime(true);
        try {
            $response = $this->provider->generate($request);
        } catch (AIException $e) {
            $this->record($userId, 'error', $e->reason, (int) ((hrtime(true) - $start) / 1e6));
            throw $e;
        }

        $response = $response->withLatency((int) ((hrtime(true) - $start) / 1e6));
        $this->record($userId, 'ok', null, $response->latencyMs, $response);

        return $response;
    }

    /** One-shot prompt (no history). */
    public function generate(string $prompt, ?string $system = null, ?int $userId = null): AIResponse
    {
        return $this->chat([['role' => 'user', 'content' => $prompt]], $system, $userId);
    }

    /** Request metadata only: never prompts or completions. */
    private function record(?int $userId, string $outcome, ?string $reason, int $latencyMs, ?AIResponse $r = null): void
    {
        Log::info('ai.request', array_filter([
            'user_id' => $userId,
            'provider' => $this->provider->name(),
            'model' => $r?->model ?? $this->provider->model(),
            'outcome' => $outcome,
            'reason' => $reason,
            'latency_ms' => $latencyMs,
            'prompt_tokens' => $r?->promptTokens,
            'completion_tokens' => $r?->completionTokens,
        ], fn ($v) => $v !== null));
    }
}
