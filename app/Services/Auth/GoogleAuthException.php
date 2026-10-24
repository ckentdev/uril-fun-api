<?php

namespace App\Services\Auth;

use RuntimeException;

/** Carries a short, safe reason code that is shown to the SPA. */
class GoogleAuthException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
