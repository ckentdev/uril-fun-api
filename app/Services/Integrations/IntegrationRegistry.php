<?php

namespace App\Services\Integrations;

use App\Models\IntegrationTestLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Fixed allow-list of integration checks. Nothing here accepts a URL, host or command
 * from the browser: callers can only pick one of these keys.
 */
class IntegrationRegistry
{
    /** @var array<string, IntegrationChecker> */
    private array $checkers;

    public function __construct(
        ApiChecker $api,
        DatabaseChecker $database,
        AuthChecker $auth,
        GoogleChecker $google,
        MailChecker $mail,
        AiChecker $ai,
        ChatChecker $chat,
    ) {
        foreach ([$api, $database, $auth, $google, $mail, $ai, $chat] as $checker) {
            $this->checkers[$checker->key()] = $checker;
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->checkers);
    }

    public function find(string $key): ?IntegrationChecker
    {
        return $this->checkers[$key] ?? null;
    }

    /** Run one check and record a sanitized log row. */
    public function test(string $key, ?User $user): array
    {
        $result = $this->checkers[$key]->run($user);

        IntegrationTestLog::create([
            'user_id' => $user?->id,
            'integration' => $key,
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'summary' => Str::limit($result['message'], 250, ''),
        ]);

        return $result;
    }

    /**
     * Status of every integration WITHOUT hitting external services:
     * cheap local checks run live; everything else reports the last recorded test.
     */
    public function snapshot(?User $user): array
    {
        $out = [];
        foreach ($this->checkers as $key => $checker) {
            $last = IntegrationTestLog::where('integration', $key)->latest('id')->first();
            $lastSuccess = IntegrationTestLog::where('integration', $key)->where('status', IntegrationChecker::CONNECTED)->latest('id')->first();

            if ($checker->isCheapToRunLive()) {
                $live = $checker->run($user);
                $status = $live['status'];
                $message = $live['message'];
                $testedAt = $live['tested_at'];
            } elseif (! $checker->isConfigured()) {
                $status = IntegrationChecker::NOT_CONFIGURED;
                $message = 'Required settings are missing on the server.';
                $testedAt = $last?->created_at?->toIso8601String();
            } else {
                $status = $last?->status ?? IntegrationChecker::UNKNOWN;
                $message = $last ? ($last->summary ?? '') : 'Settings are present but this integration has not been tested yet.';
                $testedAt = $last?->created_at?->toIso8601String();
            }

            $out[] = [
                'key' => $key,
                'label' => $checker->label(),
                'description' => $checker->description(),
                'status' => $status,
                'configured' => $status !== IntegrationChecker::NOT_CONFIGURED,
                'message' => $message,
                'last_tested_at' => $testedAt,
                'last_success_at' => $lastSuccess?->created_at?->toIso8601String(),
                'troubleshooting' => $status === IntegrationChecker::CONNECTED ? [] : $checker->troubleshooting(),
            ];
        }

        return $out;
    }
}
