<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_be_created_without_a_default_password(): void
    {
        $this->artisan('admin:create', ['email' => 'ADMIN@chiqui.test', '--name' => 'Chiqui'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'UnaClaveMuySegura42')
            ->expectsQuestion('Repetir contraseña', 'UnaClaveMuySegura42')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('admin@chiqui.test', $user->email);
        $this->assertSame('Chiqui', $user->name);
        $this->assertNotSame('UnaClaveMuySegura42', $user->password);
        $this->assertTrue(Hash::check('UnaClaveMuySegura42', $user->password));
    }

    public function test_short_or_unconfirmed_passwords_do_not_create_an_admin(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@chiqui.test', '--name' => 'Chiqui'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')
            ->expectsQuestion('Repetir contraseña', 'distinta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
