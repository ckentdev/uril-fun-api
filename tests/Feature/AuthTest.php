<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Requests from the SPA origin get the stateful (session) middleware. */
    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }

    public function test_user_can_register_and_receives_verification_email(): void
    {
        Notification::fake();

        $this->spa()->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertNotSame('secret123', $user->password);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->spa()->postJson('/api/v1/auth/register', [
            'name' => '', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'nope',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_login_and_fetch_profile_then_logout(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.email', 'ada@example.com');

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');

        $this->spa()->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_wrong_credentials_get_generic_error(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])
                ->assertUnprocessable();
        }

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])
            ->assertStatus(429);
    }

    public function test_protected_endpoints_reject_guests(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->patchJson('/api/v1/auth/profile', [])->assertUnauthorized();
        $this->putJson('/api/v1/auth/password', [])->assertUnauthorized();
    }

    public function test_profile_update_resets_verification_when_email_changes(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/auth/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo($user->fresh(), VerifyEmail::class);
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_password_can_be_changed(): void
    {
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertTrue(\Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        // Unknown emails get the same response.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringStartsWith('http://localhost:5173/reset-password?', $url);

            return true;
        });

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertTrue(\Hash::check('brandnew123', $user->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid', 'email' => $user->email,
            'password' => 'another123', 'password_confirmation' => 'another123',
        ])->assertUnprocessable();
    }

    public function test_email_verification_link_verifies_user(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect('http://localhost:5173/verify-email?status=success');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_link_rejects_tampering(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => 'wronghash',
        ]);
        $this->get($url)->assertRedirect('http://localhost:5173/verify-email?status=invalid');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_notification_requires_auth_and_sends(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();

        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
