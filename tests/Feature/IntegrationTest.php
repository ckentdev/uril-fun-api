<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\IntegrationTestLog;
use App\Models\User;
use App\Support\Redactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function find(array $items, string $key): array
    {
        return collect($items)->firstWhere('key', $key);
    }

    public function test_detailed_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/integrations/status')->assertUnauthorized();
        $this->postJson('/api/v1/integrations/database/test')->assertUnauthorized();
    }

    public function test_health_endpoint_stays_minimal_and_public(): void
    {
        $body = $this->getJson('/api/v1/health')->assertOk()->json();
        $this->assertSame(['status', 'time'], array_keys($body));
    }

    public function test_status_reports_local_checks_live_and_does_not_fake_the_rest(): void
    {
        config(['mail.default' => 'log', 'services.google.client_id' => null, 'ai.provider' => 'openai', 'ai.api_key' => null]);
        $items = $this->actingAs(User::factory()->create())->getJson('/api/v1/integrations/status')->assertOk()->json('data');

        $this->assertSame('connected', $this->find($items, 'api')['status']);
        $this->assertSame('connected', $this->find($items, 'database')['status']);
        $this->assertSame('connected', $this->find($items, 'auth')['status']);
        $this->assertSame('not_configured', $this->find($items, 'google')['status']);
        $this->assertSame('not_configured', $this->find($items, 'mail')['status']);
        $this->assertSame('not_configured', $this->find($items, 'ai')['status']);
        $this->assertSame('not_configured', $this->find($items, 'chat')['status']);
        $this->assertNotEmpty($this->find($items, 'ai')['troubleshooting']);
        $this->assertCount(7, $items);
    }

    public function test_configured_but_untested_integration_is_unknown_not_connected(): void
    {
        config(['ai.provider' => 'openai', 'ai.api_key' => 'sk-present', 'services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $items = $this->actingAs(User::factory()->create())->getJson('/api/v1/integrations/status')->json('data');

        $this->assertSame('unknown', $this->find($items, 'ai')['status']);
        $this->assertSame('unknown', $this->find($items, 'google')['status']);
        $this->assertNull($this->find($items, 'ai')['last_success_at']);
    }

    public function test_ai_test_with_a_failing_provider_reports_error(): void
    {
        config(['ai.provider' => 'openai', 'ai.api_key' => 'sk-bad', 'ai.base_url' => 'https://api.example.test/v1']);
        Http::fake(['*' => Http::response([], 401)]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/integrations/ai/test')
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.verified', false);
    }

    public function test_ai_test_success_records_last_success(): void
    {
        config(['ai.provider' => 'openai', 'ai.api_key' => 'sk-good', 'ai.base_url' => 'https://api.example.test/v1']);
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/integrations/ai/test')
            ->assertOk()->assertJsonPath('data.status', 'connected')->assertJsonPath('data.verified', true);

        $this->assertDatabaseHas('integration_test_logs', ['integration' => 'ai', 'status' => 'connected', 'user_id' => $user->id]);

        $items = $this->actingAs($user)->getJson('/api/v1/integrations/status')->json('data');
        $this->assertSame('connected', $this->find($items, 'ai')['status']);
        $this->assertNotNull($this->find($items, 'ai')['last_success_at']);
    }

    public function test_mock_provider_and_chat_checks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/integrations/ai/test')->assertJsonPath('data.status', 'connected');
        $this->actingAs($user)->postJson('/api/v1/integrations/chat/test')->assertJsonPath('data.status', 'connected');
        $this->assertDatabaseCount('chat_conversations', 0); // rolled back
    }

    public function test_google_test_never_claims_connected_without_a_real_login(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        Http::fake(['accounts.google.com/*' => Http::response(['issuer' => 'https://accounts.google.com'])]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/integrations/google/test')
            ->assertOk()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.verified', false);
    }

    public function test_unknown_integration_names_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/integrations/shell/test')->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/integrations/..%2F..%2Fetc/test')->assertNotFound();
    }

    public function test_responses_never_contain_secrets(): void
    {
        config([
            'ai.provider' => 'openai', 'ai.api_key' => 'sk-live-TOPSECRET123',
            'ai.base_url' => 'https://api.example.test/v1',
            'services.google.client_id' => 'google-client-id', 'services.google.client_secret' => 'GOOGLE-SECRET-VALUE',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.password' => 'SMTP-PASSWORD-VALUE',
        ]);
        Http::fake(['*' => Http::response(['error' => 'bad key sk-live-TOPSECRET123'], 401)]);
        $user = User::factory()->create();

        $bodies = [$this->actingAs($user)->getJson('/api/v1/integrations/status')->getContent()];
        foreach (['api', 'database', 'auth', 'google', 'mail', 'ai', 'chat'] as $key) {
            $bodies[] = $this->actingAs($user)->postJson("/api/v1/integrations/{$key}/test")->getContent();
        }
        $bodies[] = $this->actingAs($user)->getJson('/api/v1/ai/status')->getContent();

        $all = implode("\n", $bodies).implode("\n", IntegrationTestLog::pluck('summary')->all());
        foreach (['sk-live-TOPSECRET123', 'GOOGLE-SECRET-VALUE', 'SMTP-PASSWORD-VALUE', config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_integration_test_rate_limit(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/api/v1/integrations/api/test')->assertOk();
        }
        $this->actingAs($user)->postJson('/api/v1/integrations/api/test')->assertStatus(429);
    }

    public function test_redactor_masks_secrets(): void
    {
        $this->assertSame('a***@example.com', Redactor::maskEmail('alice@example.com'));
        $this->assertStringNotContainsString('abc123', Redactor::string('Authorization: Bearer abc123def456'));
        $this->assertStringNotContainsString('hunter2', Redactor::string('smtp://user:hunter2@mail.example.com'));
        $out = Redactor::array(['api_key' => 'x', 'nested' => ['Cookie' => 'laravel_session=abc'], 'ok' => 'fine']);
        $this->assertSame(Redactor::MASK, $out['api_key']);
        $this->assertSame(Redactor::MASK, $out['nested']['Cookie']);
        $this->assertSame('fine', $out['ok']);
    }

    public function test_prune_command_removes_old_logs_only(): void
    {
        $user = User::factory()->create();
        $old = EmailLog::create(['user_id' => $user->id, 'recipient_masked' => 'a***@x.com', 'subject' => 'old', 'status' => 'accepted']);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();
        EmailLog::create(['user_id' => $user->id, 'recipient_masked' => 'a***@x.com', 'subject' => 'new', 'status' => 'accepted']);
        IntegrationTestLog::create(['integration' => 'api', 'status' => 'connected'])->forceFill(['created_at' => now()->subDays(60)])->save();

        Artisan::call('hub:prune-logs');

        $this->assertSame(['new'], EmailLog::pluck('subject')->all());
        $this->assertDatabaseCount('integration_test_logs', 0);
    }

    public function test_google_check_flags_mismatched_loopback_hostnames(): void
    {
        config([
            'services.google.client_id' => 'id', 'services.google.client_secret' => 'secret',
            'app.url' => 'http://127.0.0.1:8000', 'app.frontend_url' => 'http://localhost:5173',
            'services.google.redirect' => 'http://127.0.0.1:8000/api/v1/auth/google/callback',
        ]);
        Http::fake();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/integrations/google/test')
            ->assertOk()->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.message', fn ($m) => str_contains($m, 'same hostname'));
        Http::assertNothingSent();
    }

    public function test_google_check_flags_redirect_host_not_matching_app_url(): void
    {
        config([
            'services.google.client_id' => 'id', 'services.google.client_secret' => 'secret',
            'app.url' => 'http://localhost:8000', 'app.frontend_url' => 'http://localhost:5173',
            'services.google.redirect' => 'http://127.0.0.1:8000/api/v1/auth/google/callback',
        ]);
        Http::fake();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/integrations/google/test')
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.message', fn ($m) => str_contains($m, 'GOOGLE_REDIRECT_URI'));
    }
}
