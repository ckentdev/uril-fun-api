<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(User $user, string $title = 'New conversation'): ChatConversation
    {
        return $user->conversations()->create(['title' => $title]);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/chat/conversations')->assertUnauthorized();
        $this->postJson('/api/v1/chat/conversations')->assertUnauthorized();
        $this->postJson('/api/v1/chat/conversations/1/messages', ['content' => 'x'])->assertUnauthorized();
    }

    public function test_create_list_rename_delete(): void
    {
        $user = User::factory()->create();

        $id = $this->actingAs($user)->postJson('/api/v1/chat/conversations')
            ->assertCreated()->assertJsonPath('data.title', 'New conversation')->json('data.id');

        $this->actingAs($user)->getJson('/api/v1/chat/conversations')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.messages_count', 0);

        $this->actingAs($user)->patchJson("/api/v1/chat/conversations/{$id}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');
        $this->actingAs($user)->patchJson("/api/v1/chat/conversations/{$id}", ['title' => ''])->assertUnprocessable();

        $this->actingAs($user)->deleteJson("/api/v1/chat/conversations/{$id}")->assertNoContent();
        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_send_message_persists_both_messages_and_titles_conversation(): void
    {
        $user = User::factory()->create();
        $c = $this->conversation($user);

        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'What is Laravel?'])
            ->assertCreated()
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.conversation.title', 'What is Laravel?');

        $this->assertDatabaseCount('chat_messages', 2);

        $this->actingAs($user)->getJson("/api/v1/chat/conversations/{$c->id}")
            ->assertOk()->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.role', 'assistant');
    }

    public function test_history_is_sent_to_the_provider_and_limited(): void
    {
        config(['ai.history_limit' => 3]);
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
                $this->bag->messages = $r->messages;

                return new AIResponse('ok', 'fake', 'f');
            }

            public function ping(): void {}
        });

        $user = User::factory()->create();
        $c = $this->conversation($user);
        foreach (['m1', 'm2', 'm3', 'm4'] as $i => $text) {
            $c->messages()->create(['role' => $i % 2 ? 'assistant' : 'user', 'content' => $text]);
        }

        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'm5'])->assertCreated();

        $this->assertSame(['m3', 'm4', 'm5'], array_column($bag->messages, 'content'));
    }

    public function test_provider_failure_leaves_no_partial_state(): void
    {
        $user = User::factory()->create();
        $c = $this->conversation($user);

        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'please [[fail]]'])
            ->assertStatus(502)->assertJsonPath('code', 'provider_error');

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame('New conversation', $c->fresh()->title);

        // The retry (without the failure trigger) works on the same conversation.
        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'please retry'])->assertCreated();
        $this->assertDatabaseCount('chat_messages', 2);
    }

    public function test_message_validation(): void
    {
        config(['ai.max_input_chars' => 20]);
        $user = User::factory()->create();
        $c = $this->conversation($user);

        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", [])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => str_repeat('a', 21)])->assertUnprocessable();
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_users_cannot_access_each_others_conversations(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $c = $this->conversation($owner, 'Secret');
        $c->messages()->create(['role' => 'user', 'content' => 'private']);

        $this->actingAs($intruder)->getJson('/api/v1/chat/conversations')->assertJsonCount(0, 'data');
        $this->actingAs($intruder)->getJson("/api/v1/chat/conversations/{$c->id}")->assertNotFound();
        $this->actingAs($intruder)->patchJson("/api/v1/chat/conversations/{$c->id}", ['title' => 'Hacked'])->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'hi'])->assertNotFound();
        $this->actingAs($intruder)->deleteJson("/api/v1/chat/conversations/{$c->id}")->assertNotFound();

        $this->assertSame('Secret', $c->fresh()->title);
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_deleting_a_user_removes_their_chats(): void
    {
        $user = User::factory()->create();
        $c = $this->conversation($user);
        $c->messages()->create(['role' => 'user', 'content' => 'x']);

        $user->delete();

        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_chat_rate_limit(): void
    {
        $user = User::factory()->create();
        $c = $this->conversation($user);

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'hi'])->assertCreated();
        }
        $this->actingAs($user)->postJson("/api/v1/chat/conversations/{$c->id}/messages", ['content' => 'hi'])->assertStatus(429);
    }
}
