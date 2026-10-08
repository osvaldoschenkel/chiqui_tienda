<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_management_pages_and_mutations_require_authentication(): void
    {
        foreach (['/', '/products', '/products/create', '/customers', '/customers/create', '/suppliers', '/suppliers/create', '/sales', '/sales/create', '/purchases', '/purchases/create', '/cuentas'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        foreach (['/products', '/customers', '/suppliers', '/sales', '/purchases', '/customers/1/movements', '/suppliers/1/movements', '/sales/1/cancel', '/purchases/1/cancel'] as $path) {
            $this->post($path, [])->assertRedirect(route('login'));
        }

        $this->put('/products/1', [])->assertRedirect(route('login'));
        $this->delete('/customers/1')->assertRedirect(route('login'));
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_login_renders_and_success_restores_intended_page_with_new_session_id(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $this->get(route('login'))->assertOk()->assertSee('Chiqui');
        $this->get(route('products.index'))->assertRedirect(route('login'));
        $oldSessionId = $this->app['session']->getId();

        $this->post(route('login.store'), ['email' => ' ADMIN@EXAMPLE.COM ', 'password' => 'password'])
            ->assertRedirect(route('products.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
        $this->get(route('login'))->assertRedirect(route('dashboard'));
    }

    public function test_invalid_login_does_not_authenticate_or_create_users(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->from(route('login'))->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'wrong-password'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => 'invalid-email', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
        $this->get('/register')->assertNotFound();
    }

    public function test_repeated_wrong_password_locks_attempts_even_when_next_password_is_correct(): void
    {
        $this->freezeTime();
        User::factory()->create(['email' => 'admin@example.com']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => 'ADMIN@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Demasiados intentos. Volvé a intentar en 60 segundos.']);
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_protected_pages_redirect_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private-value' => 'should-disappear', '_token' => 'old-token']);
        $oldSessionId = $this->app['session']->getId();

        $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private-value');

        $this->assertGuest();
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
        $this->assertNotSame('old-token', $this->app['session']->token());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
