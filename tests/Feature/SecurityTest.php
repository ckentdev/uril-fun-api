<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/does-not-exist')->assertNotFound();

        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertArrayNotHasKey('file', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    public function test_server_errors_are_generic_in_production(): void
    {
        config(['app.debug' => false]);
        \Route::get('/api/v1/boom', fn () => throw new \RuntimeException('db password=hunter2'));

        $response = $this->getJson('/api/v1/boom')->assertStatus(500);

        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertSame('Server Error', $response->json('message'));
    }

    public function test_user_resource_never_exposes_credentials(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'g-secret-id'])->save();

        $body = $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->getContent();

        foreach (['password', 'remember_token', 'g-secret-id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, str_replace('has_password', '', $body));
        }
    }

    public function test_cookie_session_login_does_not_issue_a_bearer_token(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
            ->assertOk();

        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
