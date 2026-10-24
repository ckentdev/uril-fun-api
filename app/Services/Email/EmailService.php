<?php

namespace App\Services\Email;

use App\Jobs\SendTestEmailJob;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Redactor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class EmailService
{
    /**
     * Send a demo email on behalf of $user. Returns the log row; its status is
     * "accepted" (transport took it), "queued" (waiting for a queue worker) or "failed".
     */
    public function sendTest(User $user, string $to, string $subject, string $body): EmailLog
    {
        $queued = config('queue.default') !== 'sync';

        $log = EmailLog::create([
            'user_id' => $user->id,
            'type' => 'test',
            'recipient_masked' => Redactor::maskEmail($to),
            'subject' => $subject,
            'status' => EmailLog::STATUS_QUEUED,
            'mailer' => config('mail.default'),
        ]);

        $job = new SendTestEmailJob($log->id, $to, $subject, $body, $user->name);
        $queued ? dispatch($job) : dispatch_sync($job);

        return $log->fresh();
    }

    public function sendWelcome(User $user): void
    {
        $log = EmailLog::create([
            'user_id' => $user->id,
            'type' => 'welcome',
            'recipient_masked' => Redactor::maskEmail($user->email),
            'subject' => 'Welcome to '.config('app.name'),
            'status' => EmailLog::STATUS_FAILED,
            'mailer' => config('mail.default'),
        ]);

        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
            $log->update(['status' => EmailLog::STATUS_ACCEPTED]);
        } catch (Throwable $e) {
            $log->update(['error' => Str::limit(Redactor::string($e->getMessage()), 250, '')]);
        }
    }

    public function dailyCount(User $user): int
    {
        return EmailLog::where('user_id', $user->id)->where('type', 'test')
            ->where('created_at', '>=', now()->subDay())->count();
    }

    /** Honest wording for the UI: what the status does and does not prove. */
    public static function describe(EmailLog $log): string
    {
        return match ($log->status) {
            EmailLog::STATUS_ACCEPTED => "Accepted by the \"{$log->mailer}\" mail transport. Final delivery to the inbox is not confirmed.",
            EmailLog::STATUS_QUEUED => 'Queued. A queue worker must process it before it is handed to the mail transport.',
            default => 'The mail transport rejected or could not send the message.',
        };
    }
}
