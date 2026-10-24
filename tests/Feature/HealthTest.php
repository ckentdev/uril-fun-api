<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_is_public_and_minimal(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'time'])
            ->assertJsonPath('status', 'ok');
    }

    public function test_cors_allows_configured_frontend_origin(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->getJson('/api/v1/health')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_cors_never_echoes_unknown_origin(): void
    {
        // With one allowed origin the library always answers with that origin;
        // browsers then refuse the mismatch. The unknown origin must never be echoed.
        $response = $this->withHeaders(['Origin' => 'http://evil.example'])
            ->getJson('/api/v1/health');

        $this->assertNotSame('http://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
