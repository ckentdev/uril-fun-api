<?php

namespace App\Jobs;

use App\Mail\TestEmail;
use App\Models\EmailLog;
use App\Support\Redactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands one message to the mail transport and records the outcome.
 * "accepted" means the transport took the message; it is NOT a delivery receipt.
 */
class SendTestEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $logId,
        public string $to,
        public string $subject,
        public string $body,
        public string $senderName,
    ) {}

    public function handle(): void
    {
        $log = EmailLog::find($this->logId);
        if (! $log) {
            return;
        }

        try {
            Mail::to($this->to)->send(new TestEmail($this->subject, $this->body, $this->senderName));
            $log->update(['status' => EmailLog::STATUS_ACCEPTED, 'error' => null]);
        } catch (Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => Str::limit(Redactor::string($e->getMessage()), 250, ''),
            ]);
        }
    }
}
