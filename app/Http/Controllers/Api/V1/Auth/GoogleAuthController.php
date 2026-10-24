<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAccountResolver;
use App\Services\Auth\GoogleAuthException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Browser-redirect OAuth flow. These routes run in the "web" middleware group
 * (session + cookies) because Socialite stores and validates the OAuth state
 * in the session. Every outcome redirects back to the SPA.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! self::isConfigured()) {
            return $this->toSpa('error', 'not_configured');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, GoogleAccountResolver $resolver): RedirectResponse
    {
        if (! self::isConfigured()) {
            return $this->toSpa('error', 'not_configured');
        }

        // User pressed "Cancel" on the consent screen (or Google returned any error).
        if ($request->filled('error')) {
            return $request->input('error') === 'access_denied'
                ? $this->toSpa('cancelled')
                : $this->toSpa('error', 'provider_error');
        }

        try {
            $googleUser = Socialite::driver('google')->user(); // validates OAuth state
            $user = $resolver->resolve($googleUser);
        } catch (GoogleAuthException $e) {
            return $this->toSpa('error', $e->reason);
        } catch (Throwable $e) {
            // Log the class only: exception messages can contain provider payloads.
            Log::warning('Google OAuth callback failed', ['exception' => $e::class]);

            return $this->toSpa('error', 'oauth_failed');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->toSpa('success');
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function toSpa(string $status, ?string $reason = null): RedirectResponse
    {
        $query = array_filter(['status' => $status, 'reason' => $reason]);

        return redirect(rtrim(config('app.frontend_url'), '/').'/auth/google/result?'.http_build_query($query));
    }
}
