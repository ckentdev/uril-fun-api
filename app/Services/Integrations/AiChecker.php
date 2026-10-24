<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Services\AI\AIException;
use App\Services\AI\AIService;

class AiChecker extends IntegrationChecker
{
    public function __construct(private AIService $ai) {}

    public function key(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return 'AI provider';
    }

    public function description(): string
    {
        return 'Configured AI provider used by the playground and chatbot.';
    }

    public function isConfigured(): bool
    {
        return $this->ai->provider()->isConfigured();
    }

    public function troubleshooting(): array
    {
        return [
            'Set AI_PROVIDER, AI_API_KEY and AI_MODEL in api/.env (or AI_PROVIDER=mock)',
            'Check that the key is valid and has quota',
            'See docs/AI_SETUP.md',
        ];
    }

    protected function notConfiguredMessage(): string
    {
        return 'AI provider "'.$this->ai->provider()->name().'" is not configured (missing API key or unsupported provider).';
    }

    protected function probe(?User $user): array
    {
        $p = $this->ai->provider();
        $details = ['provider' => $p->name(), 'model' => $p->model()];

        try {
            $p->ping();
        } catch (AIException $e) {
            return ['status' => self::ERROR, 'message' => $e->getMessage(), 'details' => $details + ['reason' => $e->reason]];
        }

        $message = $p->name() === 'mock'
            ? 'Built-in mock provider (no external service is contacted).'
            : 'Provider reachable and the API key was accepted.';

        return ['status' => self::CONNECTED, 'message' => $message, 'details' => $details];
    }
}
