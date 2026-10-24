<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use App\Services\AI\OpenAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/ai/knowledge')->assertUnauthorized();
        $this->putJson('/api/v1/ai/knowledge', ['content' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/v1/ai/knowledge')->assertUnauthorized();
    }

    public function test_save_read_update_and_clear(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/ai/knowledge')->assertOk()->assertJsonPath('data.content', '');

        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => '  Courses: Linux, DevOps.  '])
            ->assertOk()->assertJsonPath('data.content', 'Courses: Linux, DevOps.');
        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => 'Updated text'])->assertOk();
        $this->assertDatabaseCount('ai_knowledge', 1); // updated in place

        $this->actingAs($user)->getJson('/api/v1/ai/knowledge')->assertJsonPath('data.content', 'Updated text');

        $this->actingAs($user)->deleteJson('/api/v1/ai/knowledge')->assertOk()->assertJsonPath('data.content', '');
        $this->assertDatabaseCount('ai_knowledge', 0);
    }

    public function test_validation(): void
    {
        config(['ai.knowledge_max_chars' => 10]);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', [])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => str_repeat('a', 11)])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => '   '])->assertUnprocessable();
    }

    public function test_knowledge_is_private_to_each_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->putJson('/api/v1/ai/knowledge', ['content' => 'A secret'])->assertOk();

        $this->actingAs($b)->getJson('/api/v1/ai/knowledge')->assertJsonPath('data.content', '');
        $this->actingAs($b)->deleteJson('/api/v1/ai/knowledge');
        $this->assertDatabaseCount('ai_knowledge', 1);
    }

    public function test_saved_knowledge_reaches_the_provider_for_generate_and_chat(): void
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
                return 'f';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function generate(AIRequest $r): AIResponse
            {
                $this->bag->knowledge = $r->knowledge;

                return new AIResponse('ok', 'fake', 'f');
            }

            public function ping(): void {}
        });
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'q'])->assertOk();
        $this->assertEmpty($bag->knowledge);

        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => 'Our academy teaches DevOps.']);

        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'q'])->assertOk();
        $this->assertSame('Our academy teaches DevOps.', $bag->knowledge);

        $c = $user->conversations()->create(['title' => 'New conversation']);
        $bag->knowledge = null;
        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'hi'])->assertCreated();
        $this->assertSame('Our academy teaches DevOps.', $bag->knowledge);
    }

    public function test_server_default_is_used_only_when_user_has_none(): void
    {
        config(['ai.knowledge' => 'server default']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/ai/knowledge')->assertJsonPath('data.using_server_default', true);
        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'q'])
            ->assertJsonPath('data.content', fn ($c) => str_contains($c, '14 characters of knowledge'));

        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => 'mine!'])
            ->assertJsonPath('data.using_server_default', false);
        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'q'])
            ->assertJsonPath('data.content', fn ($c) => str_contains($c, '5 characters of knowledge'));
    }

    public function test_openai_receives_knowledge_in_the_system_message(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $provider = new OpenAIProvider('sk-test', 'https://api.example.test/v1', 'm', 5);

        $provider->generate(new AIRequest([['role' => 'user', 'content' => 'q']], 'Be brief.', 'Fact: sky is blue.'));

        Http::assertSent(fn ($req) => $req['messages'][0]['role'] === 'system'
            && str_contains($req['messages'][0]['content'], 'Be brief.')
            && str_contains($req['messages'][0]['content'], 'Fact: sky is blue.'));
    }

    public function test_knowledge_is_never_logged(): void
    {
        \Log::spy();
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/v1/ai/knowledge', ['content' => 'TOP-SECRET-KNOWLEDGE']);
        $this->actingAs($user)->postJson('/api/v1/ai/generate', ['prompt' => 'q'])->assertOk();

        \Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => ! str_contains(json_encode($ctx), 'TOP-SECRET'))->atLeast()->once();
    }
}
