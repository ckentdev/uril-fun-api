<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendTestEmailRequest;
use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailController extends Controller
{
    public function __construct(private EmailService $emails) {}

    public function test(SendTestEmailRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->emails->dailyCount($user) >= (int) config('hub.email.daily_limit')) {
            return response()->json([
                'message' => 'Daily demo email limit reached. Try again later.',
            ], 429);
        }

        $log = $this->emails->sendTest($user, $request->validated('to'), $request->validated('subject'), $request->validated('message'));

        $status = match ($log->status) {
            EmailLog::STATUS_ACCEPTED => 200,
            EmailLog::STATUS_QUEUED => 202,
            default => 502,
        };

        return (new EmailLogResource($log))->response()->setStatusCode($status);
    }

    public function logs(Request $request): AnonymousResourceCollection
    {
        return EmailLogResource::collection(
            EmailLog::where('user_id', $request->user()->id)->latest('id')->paginate(10),
        );
    }
}
