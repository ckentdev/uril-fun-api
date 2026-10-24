<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'recipient_masked', 'subject', 'status', 'mailer', 'error'])]
class EmailLog extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FAILED = 'failed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
