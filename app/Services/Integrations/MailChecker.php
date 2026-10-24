<?php

namespace App\Services\Integrations;

use App\Models\User;

class MailChecker extends IntegrationChecker
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'Email delivery';
    }

    public function description(): string
    {
        return 'Mail transport used for verification, reset and test emails.';
    }

    public function isConfigured(): bool
    {
        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array', null], true)) {
            return false; // development drivers: nothing is actually delivered
        }
        if ($mailer === 'smtp') {
            return filled(config('mail.mailers.smtp.host')) && filled(config('mail.mailers.smtp.port'));
        }

        return true;
    }

    public function troubleshooting(): array
    {
        return [
            'Set MAIL_MAILER=smtp and MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD in api/.env',
            'For local testing run Mailpit (127.0.0.1:1025)',
            'See docs/EMAIL_SETUP.md',
        ];
    }

    protected function notConfiguredMessage(): string
    {
        return 'Mailer "'.config('mail.default').'" does not deliver real email (development driver). Emails are written to the log only.';
    }

    protected function probe(?User $user): array
    {
        $mailer = config('mail.default');
        $details = ['mailer' => $mailer];

        if ($mailer !== 'smtp') {
            return ['status' => self::UNKNOWN, 'message' => "Mailer \"{$mailer}\" is configured; reachability cannot be checked here. Send a test email to verify.", 'details' => $details];
        }

        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $details += ['host' => $host, 'port' => $port];

        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        if (! $socket) {
            return ['status' => self::ERROR, 'message' => "Cannot open a connection to {$host}:{$port}.", 'details' => $details];
        }
        fclose($socket);

        return [
            'status' => self::CONNECTED,
            'message' => "SMTP server {$host}:{$port} accepts connections. Login credentials and inbox delivery are verified only by sending a test email.",
            'details' => $details,
        ];
    }
}
