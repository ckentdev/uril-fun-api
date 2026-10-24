<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Chat Completions (also works with OpenAI-compatible servers via AI_BASE_URL).
 * The API key stays in this class; prompts, completions and provider error bodies are never logged.
 */
class OpenAIProvider implements AIProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $timeout,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->model) && filled($this->baseUrl);
    }

    public function generate(AIRequest $request): AIResponse
    {
        $this->assertConfigured();

        $messages = $request->messages;
        if ($system = $request->systemWithKnowledge()) {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post('/chat/completions', [
            'model' => $this->model,
            'messages' => $messages,
        ]));

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw AIException::providerError('The AI provider returned an empty response.');
        }

        return new AIResponse(
            content: $content,
            provider: $this->name(),
            model: (string) ($response->json('model') ?? $this->model),
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
        );
    }

    public function ping(): void
    {
        $this->assertConfigured();
        // Listing models needs a valid key but consumes no tokens.
        $this->send(fn (PendingRequest $http) => $http->get('/models'));
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw AIException::notConfigured('AI_API_KEY is not set on the server.');
        }
    }

    private function send(callable $call): Response
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::warning('AI provider connection failed', ['provider' => 'openai', 'exception' => $e::class]);
            throw AIException::timeout();
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('AI provider returned an error', ['provider' => 'openai', 'status' => $response->status()]);

        throw match (true) {
            $response->status() === 429 => AIException::rateLimited(),
            in_array($response->status(), [401, 403], true) => AIException::providerError('The AI provider rejected the server credentials.'),
            $response->status() === 404 => AIException::providerError('The configured AI model or endpoint was not found.'),
            default => AIException::providerError(),
        };
    }
}
