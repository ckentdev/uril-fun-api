<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Each user's own reference text, added to every AI request they make. */
class AiKnowledgeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond(AiKnowledge::where('user_id', $request->user()->id)->first());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:'.config('ai.knowledge_max_chars')],
        ]);

        $content = trim($data['content']);
        abort_if($content === '', 422, 'Knowledge cannot be empty. Use Clear to remove it.');

        $userId = $request->user()->id;
        $knowledge = AiKnowledge::where('user_id', $userId)->first()
            ?? (new AiKnowledge)->forceFill(['user_id' => $userId]); // user_id is not mass assignable
        $knowledge->content = $content;
        $knowledge->save();

        return $this->respond($knowledge);
    }

    public function destroy(Request $request): JsonResponse
    {
        AiKnowledge::where('user_id', $request->user()->id)->delete();

        return $this->respond(null);
    }

    private function respond(?AiKnowledge $knowledge): JsonResponse
    {
        return response()->json(['data' => [
            'content' => $knowledge?->content ?? '',
            'updated_at' => $knowledge?->updated_at?->toIso8601String(),
            'max_chars' => (int) config('ai.knowledge_max_chars'),
            // True when no personal text is saved and the server-wide AI_KNOWLEDGE (if any) applies.
            'using_server_default' => $knowledge === null && filled(config('ai.knowledge')),
        ]]);
    }
}
