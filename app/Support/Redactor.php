<?php

namespace App\Support;

/**
 * Removes secrets from strings and arrays before they are logged or returned.
 * Used for email errors, integration test output and provider error text.
 */
class Redactor
{
    public const MASK = '[redacted]';

    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie',
        'set-cookie', 'key', 'client_secret', 'access_token', 'refresh_token', 'session', 'xsrf',
    ];

    /** Config values that must never appear in output, even if embedded in an error message. */
    private static function knownSecrets(): array
    {
        return array_values(array_filter([
            config('app.key'),
            config('ai.api_key'),
            config('services.google.client_secret'),
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.username'),
        ], fn ($v) => is_string($v) && strlen($v) >= 4));
    }

    public static function string(string $value): string
    {
        foreach (self::knownSecrets() as $secret) {
            $value = str_replace($secret, self::MASK, $value);
        }

        $patterns = [
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i' => '$1'.self::MASK,
            '/(sk-[A-Za-z0-9_\-]{8,})/' => self::MASK,
            '/(:\/\/[^:\/\s]+:)[^@\/\s]+@/' => '$1'.self::MASK.'@',
            '/((?:api[_-]?key|token|secret|password|passwd|pwd)["\']?\s*[=:]\s*["\']?)[^\s"\'&,;]+/i' => '$1'.self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
    }

    public static function array(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = self::array($value);
            } elseif (is_string($value)) {
                $out[$key] = self::string($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if ($key === $needle || str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** a***@example.com */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 1).'***@'.$domain;
    }
}
