<?php

namespace App\Services\Integrations;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\AI\AIService;
use Illuminate\Support\Facades\DB;

class ChatChecker extends IntegrationChecker
{
    public function __construct(private AIService $ai) {}

    public function key(): string
    {
        return 'chat';
    }

    public function label(): string
    {
        return 'AI chatbot';
    }

    public function description(): string
    {
        return 'Persistent chat: database storage plus an available AI provider.';
    }

    public function isConfigured(): bool
    {
        return $this->ai->provider()->isConfigured();
    }

    public function troubleshooting(): array
    {
        return ['Run: php artisan migrate', 'Configure the AI provider (see the AI provider card)'];
    }

    protected function notConfiguredMessage(): string
    {
        return 'The chatbot needs a configured AI provider.';
    }

    protected function probe(?User $user): array
    {
        if (! $user) {
            return ['status' => self::ERROR, 'message' => 'No authenticated user on this request.'];
        }

        // Prove the chat tables are writable without leaving data behind, and without spending AI tokens.
        DB::beginTransaction();
        try {
            $c = $user->conversations()->create(['title' => 'integration check']);
            $c->messages()->create(['role' => 'user', 'content' => 'ping']);
            $count = ChatConversation::where('id', $c->id)->count();
        } finally {
            DB::rollBack();
        }

        return [
            'status' => $count === 1 ? self::CONNECTED : self::ERROR,
            'message' => 'Chat storage read/write works (rolled back). The AI reply path is covered by the AI provider check.',
            'details' => ['provider' => $this->ai->provider()->name()],
        ];
    }
}
