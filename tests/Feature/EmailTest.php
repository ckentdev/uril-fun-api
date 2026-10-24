<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge(['to' => 'friend@example.com', 'subject' => 'Hello', 'message' => 'Testing 1 2 3'], $over);
    }

    public function test_guests_cannot_send_or_list(): void
    {
        $this->postJson('/api/v1/email/test', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/email/logs')->assertUnauthorized();
    }

    public function test_user_sends_test_email_and_gets_honest_status(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.recipient', 'f***@example.com')
            ->assertJsonPath('data.detail', fn ($d) => str_contains($d, 'not confirmed'));

        Mail::assertSent(TestEmail::class, fn ($m) => $m->hasTo('friend@example.com') && $m->mailSubject === 'Hello');
        $this->assertDatabaseHas('email_logs', ['user_id' => $user->id, 'status' => 'accepted', 'type' => 'test']);
        // The raw recipient and body are never stored.
        $this->assertDatabaseMissing('email_logs', ['recipient_masked' => 'friend@example.com']);
    }

    public function test_transport_failure_is_reported_and_logged_without_secrets(): void
    {
        config(['mail.mailers.smtp.password' => 'hunter2-secret']);
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Auth failed for password=hunter2-secret'));
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'failed');

        $error = EmailLog::first()->error;
        $this->assertStringNotContainsString('hunter2-secret', $error);
        $this->assertSame('failed', EmailLog::first()->status);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/email/test', [
            'to' => ['a@example.com', 'b@example.com'], 'subject' => "Hi\r\nBcc: x@evil.test", 'message' => str_repeat('x', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors(['to', 'subject', 'message']);

        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload(['to' => 'a@example.com, b@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_recipient_domain_allowlist(): void
    {
        Mail::fake();
        config(['hub.email.allowed_domains' => 'example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload(['to' => 'x@other.org']))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())->assertOk();
    }

    public function test_per_minute_rate_limit(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())->assertOk();
        }
        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())->assertStatus(429);
    }

    public function test_daily_cap(): void
    {
        Mail::fake();
        config(['hub.email.daily_limit' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())->assertOk();
        $this->actingAs($user)->postJson('/api/v1/email/test', $this->payload())->assertStatus(429);
    }

    public function test_logs_are_scoped_to_the_owner(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        EmailLog::create(['user_id' => $mine->id, 'recipient_masked' => 'a***@x.com', 'subject' => 'mine', 'status' => 'accepted']);
        EmailLog::create(['user_id' => $other->id, 'recipient_masked' => 'b***@x.com', 'subject' => 'theirs', 'status' => 'accepted']);

        $this->actingAs($mine)->getJson('/api/v1/email/logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'mine');
    }

    public function test_welcome_email_is_sent_on_registration_and_survives_mail_failure(): void
    {
        Mail::fake();
        $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        Mail::assertSent(WelcomeMail::class, fn ($m) => $m->hasTo('new@example.com'));
    }

    public function test_registration_succeeds_even_if_mail_transport_is_down(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->assertDatabaseHas('email_logs', ['type' => 'welcome', 'status' => 'failed']);
    }
}
