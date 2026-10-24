<?php

namespace App\Http\Resources;

use App\Models\EmailLog;
use App\Services\Email\EmailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailLog */
class EmailLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'recipient' => $this->recipient_masked,
            'subject' => $this->subject,
            'status' => $this->status,
            'mailer' => $this->mailer,
            'detail' => EmailService::describe($this->resource),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
