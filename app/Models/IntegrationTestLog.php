<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'integration', 'status', 'http_status', 'duration_ms', 'summary'])]
class IntegrationTestLog extends Model
{
    public const UPDATED_AT = null;
}
