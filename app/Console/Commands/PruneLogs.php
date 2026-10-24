<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\IntegrationTestLog;
use Illuminate\Console\Command;

class PruneLogs extends Command
{
    protected $signature = 'hub:prune-logs';

    protected $description = 'Delete email and integration test logs older than LOG_RETENTION_DAYS';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('hub.log_retention_days')));

        $emails = EmailLog::where('created_at', '<', $cutoff)->delete();
        $tests = IntegrationTestLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$emails} email log(s) and {$tests} integration test log(s).");

        return self::SUCCESS;
    }
}
