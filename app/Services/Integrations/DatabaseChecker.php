<?php

namespace App\Services\Integrations;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DatabaseChecker extends IntegrationChecker
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function description(): string
    {
        return 'Database connection used for users, sessions and chats.';
    }

    public function isConfigured(): bool
    {
        return filled(config('database.default'));
    }

    public function isCheapToRunLive(): bool
    {
        return true;
    }

    public function troubleshooting(): array
    {
        return [
            'Check DB_* values in api/.env',
            'Ensure the database server is running (or use DB_CONNECTION=sqlite)',
            'Run: php artisan migrate',
        ];
    }

    protected function probe(?User $user): array
    {
        DB::select('select 1');

        return [
            'status' => self::CONNECTED,
            'message' => 'Query succeeded.',
            'details' => ['driver' => config('database.default')],
        ];
    }
}
