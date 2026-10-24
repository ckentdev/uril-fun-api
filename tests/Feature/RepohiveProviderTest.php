<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AIException;
use App\Services\AI\AIRequest;
use App\Services\AI\RepohiveProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepohiveProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(?string $key = 'hbc_ai_test_secret'): RepohiveProvider
    {
        return new RepohiveProvider($key, 'https://apicloud.example.test/api', 'We teach Cloud Hosting.', 5);
    }

    public function test_sends_the_documented_request_shape(): void
    {
        Http::fake(['*' => Http::response(['reply' => 'Try DevOps.'])]);

        $r = $this->provider()->generate(new AIRequest([['role' => 'user', 'content' => 'What should I study?']], 'You are an advisor.'));

        $this->assertSame('Try DevOps.', $r->content);
        $this->assertSame('repohive', $r->provider);
        Http::assertSent(fn ($req) => $req->url() === 'https://apicloud.example.test/api/ai/chat'
            && $req->hasHeader('Authorization', 'Bearer hbc_ai_test_secret')
            && $req['system_prompt'] === 'You are an advisor.'
            && $req['knowledge'] === 'We teach Cloud Hosting.'
            && $req['message'] === 'What should I study?');
    }

    public function test_earlier_turns_become_a_transcript_in_the_message(): void
    {
        Http::fake(['*' => Http::response(['data' => ['message' => 'ok']])]);

        $this->provider()->generate(new AIRequest([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello!'],
            ['role' => 'user', 'content' => 'Advice?'],
        ]));

        Http::assertSent(fn ($req) => str_contains($req['message'], 'User: Hi')
            && str_contains($req['message'], 'Assistant: Hello!')
            && str_ends_with($req['message'], 'Advice?'));
    }

    public function test_unexpected_response_shape_is_a_clean_error(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true])]);

        $this->expectException(AIException::class);
        $this->provider()->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
    }

    public function test_error_statuses_map_without_leaking_bodies(): void
    {
        $seq = Http::sequence()->push(['error' => 'secret detail'], 401)->push([], 429)->push([], 500);
        Http::fake(['*' => $seq]);

        foreach ([AIException::PROVIDER_ERROR, AIException::RATE_LIMITED, AIException::PROVIDER_ERROR] as $reason) {
            try {
                $this->provider()->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
                $this->fail('expected exception');
            } catch (AIException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('secret detail', $e->getMessage());
            }
        }
    }

    public function test_timeout_and_missing_key(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);
        try {
            $this->provider()->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame(AIException::TIMEOUT, $e->reason);
        }

        Http::fake();
        $this->assertFalse($this->provider(null)->isConfigured());
        try {
            $this->provider(null)->generate(new AIRequest([['role' => 'user', 'content' => 'x']]));
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame(AIException::NOT_CONFIGURED, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_selected_through_config_and_used_by_chat_endpoint(): void
    {
        config(['ai.provider' => 'repohive', 'ai.api_key' => 'hbc_ai_x', 'ai.repohive.base_url' => 'https://apicloud.example.test/api']);
        Http::fake(['*' => Http::response(['reply' => 'Hello from RepoHive'])]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'hi'])
            ->assertOk()->assertJsonPath('data.provider', 'repohive')->assertJsonPath('data.content', 'Hello from RepoHive');

        $c = $user->conversations()->create(['title' => 'New conversation']);
        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'hello'])
            ->assertCreated()->assertJsonPath('data.assistant_message.content', 'Hello from RepoHive');

        $status = $this->actingAs($user)->getJson('/api/v1/ai/status')->assertJsonPath('data.configured', true);
        $this->assertStringNotContainsString('hbc_ai_x', $status->getContent());
    }
}
