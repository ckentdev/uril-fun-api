<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173/auth/google/result';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
    }

    private function googleUser(array $overrides = []): SocialiteUser
    {
        $user = (new SocialiteUser)->setRaw(['email_verified' => $overrides['verified'] ?? true]);
        $user->map([
            'id' => $overrides['id'] ?? 'g-123',
            'name' => $overrides['name'] ?? 'Grace Hopper',
            'email' => $overrides['email'] ?? 'grace@example.com',
            'avatar' => 'https://example.com/a.png',
        ]);
        $user->user = ['email_verified' => $overrides['verified'] ?? true];

        return $user;
    }

    private function mockProvider(?SocialiteUser $user = null, ?\Throwable $throws = null): void
    {
        $provider = Mockery::mock(Provider::class);
        if ($throws) {
            $provider->shouldReceive('user')->andThrow($throws);
        } else {
            $provider->shouldReceive('user')->andReturn($user);
        }
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_goes_to_google(): void
    {
        $this->get('/api/v1/auth/google/redirect')
            ->assertRedirectContains('accounts.google.com');
    }

    public function test_redirect_reports_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/api/v1/auth/google/redirect')
            ->assertRedirect(self::SPA.'?status=error&reason=not_configured');
    }

    public function test_callback_creates_and_logs_in_new_user(): void
    {
        $this->mockProvider($this->googleUser());

        $this->get('/api/v1/auth/google/callback')->assertRedirect(self::SPA.'?status=success');

        $user = User::firstWhere('email', 'grace@example.com');
        $this->assertNotNull($user);
        $this->assertSame('g-123', $user->google_id);
        $this->assertSame('https://example.com/a.png', $user->avatar_url);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_signs_in_already_linked_user(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com']);
        $user->forceFill(['google_id' => 'g-123'])->save();
        $this->mockProvider($this->googleUser());

        $this->get('/api/v1/auth/google/callback')->assertRedirect(self::SPA.'?status=success');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_callback_links_verified_local_account(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com']);
        $this->mockProvider($this->googleUser());

        $this->get('/api/v1/auth/google/callback')->assertRedirect(self::SPA.'?status=success');
        $this->assertSame('g-123', $user->fresh()->google_id);
    }

    public function test_callback_refuses_to_link_unverified_local_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'grace@example.com']);
        $this->mockProvider($this->googleUser());

        $this->get('/api/v1/auth/google/callback')->assertRedirect(self::SPA.'?status=error&reason=account_exists');
        $this->assertNull($user->fresh()->google_id);
        $this->assertGuest('web');
    }

    public function test_callback_refuses_unverified_google_email(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);
        $this->mockProvider($this->googleUser(['verified' => false]));

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect(self::SPA.'?status=error&reason=google_email_unverified');
        $this->assertGuest('web');
    }

    public function test_callback_handles_cancelled_consent(): void
    {
        $this->get('/api/v1/auth/google/callback?error=access_denied')
            ->assertRedirect(self::SPA.'?status=cancelled');
        $this->assertGuest('web');
    }

    public function test_callback_handles_provider_failure_without_leaking_details(): void
    {
        $this->mockProvider(null, new \RuntimeException('invalid_state secret-detail'));

        $response = $this->get('/api/v1/auth/google/callback');
        $response->assertRedirect(self::SPA.'?status=error&reason=oauth_failed');
        $this->assertStringNotContainsString('secret-detail', $response->headers->get('Location'));
        $this->assertGuest('web');
    }
}
