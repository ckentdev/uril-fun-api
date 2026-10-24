<?php

namespace App\Services\Integrations;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class GoogleChecker extends IntegrationChecker
{
    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google OAuth';
    }

    public function description(): string
    {
        return 'Sign in with Google via Laravel Socialite.';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function troubleshooting(): array
    {
        return [
            'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in api/.env',
            'Add the redirect URI shown in details to your Google Cloud OAuth client',
            'See docs/GOOGLE_AUTH.md',
        ];
    }

    protected function notConfiguredMessage(): string
    {
        return 'GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are not set.';
    }

    protected function probe(?User $user): array
    {
        $details = ['redirect_uri' => config('services.google.redirect')];

        // Cookie-based OAuth state breaks when the API and SPA use different loopback hostnames.
        $apiHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $redirectHost = parse_url((string) config('services.google.redirect'), PHP_URL_HOST);
        $spaHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $loopback = ['localhost', '127.0.0.1'];
        if (in_array($apiHost, $loopback, true) && in_array($spaHost, $loopback, true) && $apiHost !== $spaHost) {
            return ['status' => self::ERROR, 'message' => "APP_URL uses \"{$apiHost}\" but FRONTEND_URL uses \"{$spaHost}\". Use the same hostname (localhost) in both, or sign-in cookies will be lost.", 'details' => $details];
        }
        if ($redirectHost !== $apiHost) {
            return ['status' => self::ERROR, 'message' => "GOOGLE_REDIRECT_URI host \"{$redirectHost}\" differs from APP_URL host \"{$apiHost}\". Google sign-in will fail with an invalid state.", 'details' => $details];
        }

        // Fixed public URL: proves Google is reachable, NOT that the credentials are valid.
        $response = Http::timeout(5)->acceptJson()->get('https://accounts.google.com/.well-known/openid-configuration');
        if (! $response->successful()) {
            return ['status' => self::ERROR, 'message' => 'Google is not reachable from the server.', 'details' => $details];
        }

        return [
            'status' => self::UNKNOWN,
            'message' => 'Credentials are set and Google is reachable. They can only be verified by completing a real sign-in.',
            'details' => $details,
        ];
    }
}
