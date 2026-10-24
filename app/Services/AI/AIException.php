<?php

namespace App\Services\AI;

use RuntimeException;

/** Provider failure with a safe message and a stable code for the UI. */
class AIException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const TIMEOUT = 'timeout';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_ERROR = 'provider_error';

    private function __construct(public readonly string $reason, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $detail = 'The AI provider is not configured on the server.'): self
    {
        return new self(self::NOT_CONFIGURED, $detail, 503);
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, 'The AI provider did not respond in time. Please try again.', 504);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, 'The AI provider is rate limiting requests. Please wait and try again.', 429);
    }

    public static function providerError(string $message = 'The AI provider returned an error. Please try again.'): self
    {
        return new self(self::PROVIDER_ERROR, $message, 502);
    }
}
