<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatConversationResource;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatConversation;
use App\Services\AI\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    private const DEFAULT_TITLE = 'New conversation';

    public function __construct(private AIService $ai) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ChatConversationResource::collection(
            $request->user()->conversations()->withCount('messages')->latest('updated_at')->latest('id')->limit(100)->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120']]);

        $conversation = $request->user()->conversations()->create([
            'title' => $data['title'] ?? self::DEFAULT_TITLE,
        ]);

        return (new ChatConversationResource($conversation))->response()->setStatusCode(201);
    }

    public function show(ChatConversation $conversation): ChatConversationResource
    {
        $this->authorize('view', $conversation);

        return new ChatConversationResource($conversation->load(['messages' => fn ($q) => $q->orderBy('id')]));
    }

    public function update(Request $request, ChatConversation $conversation): ChatConversationResource
    {
        $this->authorize('update', $conversation);
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $conversation->update($data);

        return new ChatConversationResource($conversation);
    }

    public function destroy(ChatConversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete(); // messages cascade

        return response()->json(null, 204);
    }

    /**
     * Flow: authorize -> validate -> build history -> call provider -> persist both messages atomically.
     * The provider call happens BEFORE anything is written, so a failed/timed-out call leaves the
     * conversation exactly as it was (no orphan user message, no half-finished state). The client
     * keeps the draft and can simply retry.
     */
    public function sendMessage(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:'.config('ai.max_input_chars')],
        ]);
        $content = trim($data['content']);
        abort_if($content === '', 422, 'Message cannot be empty.');

        $limit = max(1, (int) config('ai.history_limit'));
        $history = $conversation->messages()->latest('id')->limit($limit)->get(['role', 'content'])
            ->reverse()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        $history[] = ['role' => 'user', 'content' => $content];

        $reply = $this->ai->chat($history, userId: $request->user()->id); // throws AIException on failure

        [$userMessage, $assistantMessage] = DB::transaction(function () use ($conversation, $content, $reply) {
            $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => $content]);
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $reply->content,
                'provider' => $reply->provider,
                'model' => $reply->model,
                'prompt_tokens' => $reply->promptTokens,
                'completion_tokens' => $reply->completionTokens,
                'latency_ms' => $reply->latencyMs,
            ]);

            if ($conversation->title === self::DEFAULT_TITLE) {
                $conversation->title = Str::limit($content, 50, '…');
            }
            $conversation->touch();
            $conversation->save();

            return [$userMessage, $assistantMessage];
        });

        return response()->json(['data' => [
            'user_message' => new ChatMessageResource($userMessage),
            'assistant_message' => new ChatMessageResource($assistantMessage),
            'conversation' => new ChatConversationResource($conversation),
        ]], 201);
    }
}
