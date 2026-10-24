<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Support\Redactor;
use Illuminate\Support\Str;
use Throwable;

/**
 * One safe, fixed connectivity check per integration.
 *
 * Status semantics (never inflated):
 *  - not_configured: required settings are missing
 *  - connected:      a real check just succeeded
 *  - error:          the check ran and failed
 *  - unknown:        settings exist but the check cannot prove the integration works
 */
abstract class IntegrationChecker
{
    public const CONNECTED = 'connected';

    public const NOT_CONFIGURED = 'not_configured';

    public const ERROR = 'error';

    public const UNKNOWN = 'unknown';

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** Settings present? (no network) */
    abstract public function isConfigured(): bool;

    /** @return list<string> */
    abstract public function troubleshooting(): array;

    /** Whether the cheap status endpoint may run this check live. */
    public function isCheapToRunLive(): bool
    {
        return false;
    }

    /**
     * Perform the check.
     *
     * @return array{status: string, message: string, details?: array}
     */
    abstract protected function probe(?User $user): array;

    public function run(?User $user = null): array
    {
        $start = hrtime(true);

        if (! $this->isConfigured()) {
            $probe = ['status' => self::NOT_CONFIGURED, 'message' => $this->notConfiguredMessage()];
        } else {
            try {
                $probe = $this->probe($user);
            } catch (Throwable $e) {
                $probe = ['status' => self::ERROR, 'message' => 'Check failed: '.Str::limit($e->getMessage(), 160, '…')];
            }
        }

        $status = $probe['status'];

        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'description' => $this->description(),
            'status' => $status,
            'configured' => $status !== self::NOT_CONFIGURED,
            'verified' => $status === self::CONNECTED,
            'message' => Redactor::string($probe['message']),
            'details' => Redactor::array($probe['details'] ?? []),
            'troubleshooting' => $status === self::CONNECTED ? [] : $this->troubleshooting(),
            'duration_ms' => (int) ((hrtime(true) - $start) / 1e6),
            'tested_at' => now()->toIso8601String(),
        ];
    }

    protected function notConfiguredMessage(): string
    {
        return 'Required settings are missing on the server.';
    }
}
