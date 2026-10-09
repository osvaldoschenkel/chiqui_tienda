<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_existing_operators_and_new_accounts_default_to_customer(): void
    {
        $migration = require database_path('migrations/2026_10_09_000001_add_role_to_users_table.php');
        $migration->down();
        $existingId = DB::table('users')->insertGetId([
            'name' => 'Operador existente', 'email' => 'existing@example.test',
            'password' => Hash::make('password'),
        ]);

        $migration->up();
        $newId = DB::table('users')->insertGetId([
            'name' => 'Cliente nuevo', 'email' => 'new@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->assertTrue(User::findOrFail($existingId)->isAdmin());
        $this->assertSame('customer', User::findOrFail($newId)->role);
        $this->assertFalse(User::findOrFail($newId)->isAdmin());
    }

    public function test_mass_assignment_cannot_grant_admin_permissions(): void
    {
        $user = User::create([
            'name' => 'Cliente', 'email' => 'customer@example.test',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->assertFalse($user->isFillable('role'));
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertFalse($user->fresh()->isAdmin());
        $user->fill(['role' => 'admin'])->save();
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_customer_login_discards_admin_intended_url_and_redirects_to_shop(): void
    {
        $customer = User::factory()->customer()->create(['email' => 'customer@example.test']);
        $this->get(route('products.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('shop.index'))->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($customer);
        $this->get(route('login'))->assertRedirect(route('shop.index'));
        $this->get(route('dashboard'))->assertRedirect(route('shop.index'));
    }

    public function test_customer_cannot_read_or_mutate_any_management_route_even_for_unknown_ids(): void
    {
        $this->actingAs(User::factory()->customer()->create());
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->getName() ?? '', [
                'products.', 'customers.', 'suppliers.', 'sales.', 'purchases.', 'settings.', 'accounts.',
            ])) {
                continue;
            }

            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
            $path = '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, $path)->assertForbidden();
                $checked++;
            }
        }

        $this->assertGreaterThan(30, $checked);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_customer_cannot_invoke_dashboard_data_or_manage_customer_orders(): void
    {
        $this->actingAs(User::factory()->customer()->create());
        $managementQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$managementQueries): void {
            if (preg_match('/\b(sales|purchases|products|customers|suppliers|ledger_entries|customer_orders)\b/i', $query->sql)) {
                $managementQueries[] = $query->sql;
            }
        });
        $this->get(route('dashboard'))->assertRedirect(route('shop.index'));
        $this->getJson(route('dashboard.data'))->assertForbidden();
        $this->get(route('shop.admin.orders.index'))->assertForbidden();

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->getName() ?? '', 'shop.admin.')) {
                continue;
            }

            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
            $path = '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, $path)->assertForbidden();
            }
        }

        $this->assertSame([], $managementQueries, 'Forbidden requests must not read management or order data.');
    }

    public function test_customer_can_log_out_and_session_is_invalidated(): void
    {
        $this->actingAs(User::factory()->customer()->create())
            ->withSession(['private-value' => 'remove-on-logout']);

        $this->post(route('logout'))->assertRedirect(route('login'))
            ->assertSessionMissing('private-value');
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
