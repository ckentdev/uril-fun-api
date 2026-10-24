<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RepoHive Cloud AI: POST {base}/ai/chat with a Bearer key and the body
 * {system_prompt, knowledge, message}. The key stays server-side; prompts,
 * replies and provider error bodies are never logged.
 *
 * The API takes a single "message", so earlier turns of a conversation are sent
 * as a short transcript in front of the newest user message.
 */
class RepohiveProvider implements AIProviderInterface
{
    /** Where the reply text may be, in order of preference. */
    private const REPLY_PATHS = [
        'data.reply', 'data.message', 'data.response', 'data.content', 'data.answer',
        'reply', 'message', 'response', 'content', 'answer', 'choices.0.message.content',
    ];

    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private ?string $knowledge,
        private int $timeout,
    ) {}

    public function name(): string
    {
        return 'repohive';
    }

    public function model(): string
    {
        return 'repohive-ai';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->baseUrl);
    }

    public function generate(AIRequest $request): AIResponse
    {
        $response = $this->send([
            'system_prompt' => $request->system ?: config('ai.system_prompt'),
            'knowledge' => $request->knowledge ?: ($this->knowledge ?: ''),
            'message' => $this->buildMessage($request->messages),
        ]);

        $content = null;
        foreach (self::REPLY_PATHS as $path) {
            $value = $response->json($path);
            if (is_string($value) && trim($value) !== '') {
                $content = $value;
                break;
            }
        }

        if ($content === null) {
            Log::warning('RepoHive AI reply had an unexpected shape', ['keys' => array_keys((array) $response->json())]);
            throw AIException::providerError('The AI provider returned a response in an unexpected format.');
        }

        return new AIResponse(
            content: $content,
            provider: $this->name(),
            model: (string) ($response->json('model') ?? $response->json('data.model') ?? $this->model()),
            promptTokens: $response->json('usage.prompt_tokens') ?? $response->json('data.usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens') ?? $response->json('data.usage.completion_tokens'),
        );
    }

    /** No documented health endpoint, so this sends one minimal real request (a few tokens). */
    public function ping(): void
    {
        $this->generate(new AIRequest([['role' => 'user', 'content' => 'ping']], 'Reply with the single word: pong.'));
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    private function buildMessage(array $messages): string
    {
        $latest = (string) (array_pop($messages)['content'] ?? '');
        if ($messages === []) {
            return $latest;
        }

        $transcript = collect($messages)
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Assistant' : 'User').': '.$m['content'])
            ->implode("\n");

        return "Conversation so far:\n{$transcript}\n\nNew message from the user:\n{$latest}";
    }

    private function send(array $payload): Response
    {
        if (! $this->isConfigured()) {
            throw AIException::notConfigured('AI_API_KEY is not set on the server.');
        }

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post('/ai/chat', $payload);
        } catch (ConnectionException $e) {
            Log::warning('AI provider connection failed', ['provider' => 'repohive', 'exception' => $e::class]);
            throw AIException::timeout();
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('AI provider returned an error', ['provider' => 'repohive', 'status' => $response->status()]);

        throw match (true) {
            $response->status() === 429 => AIException::rateLimited(),
            in_array($response->status(), [401, 403], true) => AIException::providerError('The AI provider rejected the server credentials.'),
            $response->status() === 404 => AIException::providerError('The AI provider endpoint was not found.'),
            $response->status() === 422 => AIException::providerError('The AI provider rejected the request.'),
            default => AIException::providerError(),
        };
    }
}
