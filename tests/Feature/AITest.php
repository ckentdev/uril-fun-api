<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use App\Services\AI\OpenAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AITest extends TestCase
{
    use RefreshDatabase;

    private function openai(?string $key = 'sk-test-secret-key'): OpenAIProvider
    {
        return new OpenAIProvider($key, 'https://api.example.test/v1', 'gpt-test', 5);
    }

    public function test_guests_are_rejected(): void
    {
        $this->postJson('/api/v1/ai/generate', ['prompt' => 'hi'])->assertUnauthorized();
        $this->getJson('/api/v1/ai/status')->assertUnauthorized();
    }

    public function test_generate_with_mock_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'Hello there'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'mock')
            ->assertJsonPath('data.content', fn ($c) => str_contains($c, 'Hello there'))
            ->assertJsonStructure(['data' => ['content', 'provider', 'model', 'usage' => ['prompt_tokens', 'completion_tokens'], 'latency_ms']]);
    }

    public function test_generate_validates_prompt_length(): void
    {
        config(['ai.max_input_chars' => 10]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => str_repeat('a', 11)])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->actingAs($user)->postJson('/api/v1/ai/generate', [])->assertUnprocessable();
    }

    public function test_generate_uses_a_fake_provider_and_system_instruction(): void
    {
        $bag = new \stdClass;
        $this->app->bind(AIProviderInterface::class, fn () => new class($bag) implements AIProviderInterface
        {
            public function __construct(public \stdClass $bag) {}

            public function name(): string
            {
                return 'fake';
            }

            public function model(): string
            {
                return 'fake-1';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function generate(AIRequest $request): AIResponse
            {
                $this->bag->request = $request;

                return new AIResponse('fake answer', 'fake', 'fake-1', 3, 4);
            }

            public function ping(): void {}
        });

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/ai/generate', ['prompt' => 'Q', 'system' => 'Be terse'])
            ->assertOk()->assertJsonPath('data.content', 'fake answer')->assertJsonPath('data.usage.prompt_tokens', 3);

        $this->assertSame('Be terse', $bag->request->system);
    }

    public function test_status_never_exposes_secrets(): void
    {
        config(['ai.provider' => 'openai', 'ai.api_key' => 'sk-super-secret-value', 'ai.model' => 'gpt-test']);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.configured', true);

        $this->assertStringNotContainsString('sk-super-secret-value', $response->getContent());
    }

    public function test_status_reports_unconfigured_and_unsupported(): void
    {
        $user = User::factory()->create();

        config(['ai.provider' => 'openai', 'ai.api_key' => null]);
        $this->actingAs($user)->getJson('/api/v1/ai/status')->assertJsonPath('data.configured', false);

        config(['ai.provider' => 'nonsense']);
        $this->actingAs($user)->getJson('/api/v1/ai/status')->assertJsonPath('data.configured', false);
        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'x'])
            ->assertStatus(503)->assertJsonPath('code', 'not_configured');
    }

    public function test_openai_provider_parses_response_and_sends_bearer_key(): void
    {
        Http::fake(['api.example.test/*' => Http::response([
            'model' => 'gpt-test-2024',
            'choices' => [['message' => ['content' => 'Hi from OpenAI']]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 5],
        ])]);

        $r = $this->openai()->generate(new AIRequest([['role' => 'user', 'content' => 'Hi']], 'sys'));

        $this->assertSame('Hi from OpenAI', $r->content);
        $this->assertSame(11, $r->promptTokens);
        Http::assertSent(function ($req) {
            return $req->hasHeader('Authorization', 'Bearer sk-test-secret-key')
                && $req->url() === 'https://api.example.test/v1/chat/completions'
                && $req['messages'][0] === ['role' => 'system', 'content' => 'sys']
                && $req['model'] === 'gpt-test';
        });
    }

    public function test_openai_provider_maps_errors(): void
    {
        $cases = [
            [429, AIException::RATE_LIMITED],
            [401, AIException::PROVIDER_ERROR],
            [500, AIException::PROVIDER_ERROR],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$status]) {
            $sequence->push(['error' => ['message' => 'secret provider detail']], $status);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as [$status, $reason]) {
            try {
                $this->openai()->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
                $this->fail('expected exception');
            } catch (AIException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('secret provider detail', $e->getMessage());
            }
        }
    }

    public function test_openai_provider_maps_timeouts(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        try {
            $this->openai()->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame(AIException::TIMEOUT, $e->reason);
        }
    }

    public function test_openai_provider_without_key_is_not_configured_and_makes_no_request(): void
    {
        Http::fake();

        $this->expectException(AIException::class);
        try {
            $this->openai(null)->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_http_endpoint_surfaces_provider_failures(): void
    {
        config(['ai.provider' => 'openai', 'ai.api_key' => 'sk-k', 'ai.base_url' => 'https://api.example.test/v1']);
        Http::fake(['*' => Http::response([], 429)]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/generate', ['prompt' => 'x'])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_logs_contain_metadata_but_never_prompt_or_key(): void
    {
        Log::spy();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/generate', ['prompt' => 'my private prompt'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) {
            return $msg === 'ai.request'
                && ! str_contains(json_encode($ctx), 'my private prompt')
                && isset($ctx['provider'], $ctx['latency_ms']);
        })->once();
    }

    public function test_ai_rate_limit(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'x'])->assertOk();
        }
        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'x'])->assertStatus(429);
    }
}
