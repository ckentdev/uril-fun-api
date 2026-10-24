<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content'])]
class AiKnowledge extends Model
{
    protected $table = 'ai_knowledge';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
