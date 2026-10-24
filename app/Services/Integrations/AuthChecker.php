<?php

namespace App\Services\Integrations;

use App\Models\User;

class AuthChecker extends IntegrationChecker
{
    public function key(): string
    {
        return 'auth';
    }

    public function label(): string
    {
        return 'Authentication';
    }

    public function description(): string
    {
        return 'Cookie-based Sanctum session for this browser.';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isCheapToRunLive(): bool
    {
        return true;
    }

    public function troubleshooting(): array
    {
        return [
            'Sign in again',
            'Make sure FRONTEND_URL / SANCTUM_STATEFUL_DOMAINS match the address in your browser bar',
            'Use localhost consistently (not 127.0.0.1 in one place)',
        ];
    }

    protected function probe(?User $user): array
    {
        if (! $user) {
            return ['status' => self::ERROR, 'message' => 'No authenticated user on this request.'];
        }

        return [
            'status' => self::CONNECTED,
            'message' => 'Authenticated session is valid.',
            'details' => [
                'user_id' => $user->id,
                'email_verified' => $user->hasVerifiedEmail(),
                'guard' => 'sanctum (session cookie)',
                'session_driver' => config('session.driver'),
            ],
        ];
    }
}
