<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds one demo account for LOCAL development only.
     * Login: demo@example.com / password
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping demo user in production.');

            return;
        }

        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
        ]);
    }
}
