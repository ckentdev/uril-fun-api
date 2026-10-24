<?php

namespace App\Services\Integrations;

use App\Models\User;

class ApiChecker extends IntegrationChecker
{
    public function key(): string
    {
        return 'api';
    }

    public function label(): string
    {
        return 'Backend API';
    }

    public function description(): string
    {
        return 'Laravel REST API is booted and answering requests.';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isCheapToRunLive(): bool
    {
        return true;
    }

    public function troubleshooting(): array
    {
        return ['Start the API: php artisan serve', 'Check VITE_API_BASE_URL in web/.env'];
    }

    protected function probe(?User $user): array
    {
        return ['status' => self::CONNECTED, 'message' => 'API is responding.', 'details' => ['environment' => app()->environment()]];
    }
}
