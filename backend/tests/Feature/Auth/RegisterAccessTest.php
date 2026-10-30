<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_closed_by_default(): void
    {
        $usersBefore = User::count();

        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'ปิดรับสมัครสมาชิกแล้ว']);
        $this->assertSame($usersBefore, User::count());
    }

    public function test_register_works_when_registration_open(): void
    {
        config(['auth.registration_open' => true]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'open@example.com',
            'password' => 'P@ssw0rd!<>Special&*',
            'password_confirmation' => 'P@ssw0rd!<>Special&*',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'open@example.com']);
    }
}
