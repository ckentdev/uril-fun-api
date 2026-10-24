<?php

namespace App\Providers;

use App\Services\AI\AIProviderInterface;
use App\Services\AI\MockAIProvider;
use App\Services\AI\OpenAIProvider;
use App\Services\AI\RepohiveProvider;
use App\Services\AI\UnsupportedProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIProviderInterface::class, fn () => match ($name = strtolower((string) config('ai.provider'))) {
            'mock' => new MockAIProvider,
            'openai' => new OpenAIProvider(
                config('ai.api_key'),
                (string) config('ai.base_url'),
                (string) config('ai.model'),
                (int) config('ai.timeout'),
            ),
            'repohive' => new RepohiveProvider(
                config('ai.api_key'),
                (string) config('ai.repohive.base_url'),
                config('ai.knowledge'),
                (int) config('ai.timeout'),
            ),
            default => new UnsupportedProvider($name),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset links open the SPA, which then calls the API.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim(config('app.frontend_url'), '/');

            return $frontend.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('auth-mail', fn (Request $request) => Limit::perMinute(3)
            ->by(($request->user()?->id ?? strtolower((string) $request->input('email'))).'|'.$request->ip()));

        RateLimiter::for('email', fn (Request $request) => Limit::perMinute((int) config('hub.limits.email', 3))
            ->by('email|'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute((int) config('hub.limits.ai', 20))
            ->by('ai|'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute((int) config('hub.limits.chat', 20))
            ->by('chat|'.($request->user()?->id ?? $request->ip())));
    }
}
