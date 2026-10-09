<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_is_created_with_customer_role_and_hidden_password_input(): void
    {
        $this->artisan('customer:create', ['email' => ' CUSTOMER@chiqui.test ', '--name' => 'Cliente Chiqui'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'UnaClaveClienteSegura42')
            ->expectsQuestion('Repetir contraseña', 'UnaClaveClienteSegura42')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('customer@chiqui.test', $user->email);
        $this->assertSame('Cliente Chiqui', $user->name);
        $this->assertSame('customer', $user->role);
        $this->assertFalse($user->isAdmin());
        $this->assertNotSame('UnaClaveClienteSegura42', $user->password);
        $this->assertTrue(Hash::check('UnaClaveClienteSegura42', $user->password));
    }

    public function test_short_or_unconfirmed_password_does_not_create_a_customer(): void
    {
        $this->artisan('customer:create', ['email' => 'customer@chiqui.test', '--name' => 'Cliente'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')
            ->expectsQuestion('Repetir contraseña', 'distinta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
