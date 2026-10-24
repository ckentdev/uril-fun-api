<?php

namespace App\Listeners;

use App\Services\Email\EmailService;
use Illuminate\Auth\Events\Registered;

class SendWelcomeEmail
{
    public function __construct(private EmailService $emails) {}

    public function handle(Registered $event): void
    {
        $this->emails->sendWelcome($event->user); // never throws; failures are logged
    }
}
