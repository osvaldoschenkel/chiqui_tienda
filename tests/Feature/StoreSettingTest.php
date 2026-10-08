<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create();
        $this->actingAs($this->operator);
        Storage::fake('public');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'Almacén Las Flores', 'tagline' => 'Tu tienda de todos los días.', 'color' => '#1d7669'], $overrides);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chiqui-brand-');
        file_put_contents($path, $contents);
        $this->beforeApplicationDestroyed(function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        // Real files exercise content-based MIME detection, unlike Laravel's filename-based fake File.
        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return $this->upload($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lX8AAAAASUVORK5CYII='));
    }

    private function receipt(): Sale
    {
        $requestId = (string) Str::uuid();

        return Sale::create([
            'number' => 'BRAND-'.$requestId, 'request_id' => $requestId, 'request_hash' => hash('sha256', $requestId),
            'payment_method' => 'cash', 'total_cents' => 100, 'status' => 'completed', 'user_id' => $this->operator->id,
        ]);
    }

    public function test_store_settings_page_and_updates_require_authentication(): void
    {
        $this->app['auth']->logout();
        $this->get(route('settings.edit'))->assertRedirect(route('login'));
        $this->put(route('settings.update'), $this->payload())->assertRedirect(route('login'));
        $this->putJson(route('settings.update'), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('store_settings', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_default_branding_reads_do_not_create_a_configuration_row(): void
    {
        $this->get(route('settings.edit'))->assertOk()->assertSee('Chiqui Tienda')->assertSee('Pequeña tienda. Grandes posibilidades.')
            ->assertDontSee('@endif', false)->assertDontSee('@include(', false);
        $this->get(route('products.index'))->assertOk()->assertSee('Chiqui Tienda');
        $this->get(route('sales.show', $this->receipt()))->assertOk()->assertSee('Chiqui Tienda');
        $this->app['auth']->logout();
        $this->get(route('login'))->assertOk()->assertSee('Chiqui Tienda');
        $this->assertDatabaseCount('store_settings', 0);
    }

    public function test_branding_updates_one_persistent_row_and_appears_on_login_layout_and_receipts(): void
    {
        $this->put(route('settings.update'), $this->payload([
            'name' => '  Tienda Día & Noche  ', 'tagline' => '  Cerca tuyo.  ', 'id' => 99, 'logo_path' => 'untrusted.png',
        ]))->assertRedirect(route('settings.edit'))->assertSessionHas('success');
        $this->assertDatabaseHas('store_settings', ['id' => 1, 'name' => 'Tienda Día & Noche', 'tagline' => 'Cerca tuyo.', 'color' => '#1d7669', 'logo_path' => null]);
        $this->assertDatabaseCount('store_settings', 1);

        $this->get(route('settings.edit'))->assertOk()->assertSee('Tienda Día & Noche');
        $this->get(route('products.index'))->assertOk()->assertSee('Tienda Día & Noche')->assertSee('#1d7669', false);
        $this->get(route('sales.show', $this->receipt()))->assertOk()->assertSee('Tienda Día & Noche');
        $this->app['auth']->logout();
        $this->get(route('login'))->assertOk()->assertSee('Tienda Día & Noche')->assertSee('Cerca tuyo.')->assertSee('#1d7669', false);

        $this->actingAs($this->operator);
        $this->put(route('settings.update'), $this->payload(['name' => 'Nueva identidad', 'tagline' => '   ', 'color' => '#245ca6']))->assertRedirect(route('settings.edit'));
        $this->assertDatabaseCount('store_settings', 1);
        $this->assertDatabaseHas('store_settings', ['id' => 1, 'name' => 'Nueva identidad', 'tagline' => null, 'color' => '#245ca6']);
        $this->get(route('products.index'))->assertOk()->assertSee('Nueva identidad')->assertSee('#245ca6', false)->assertDontSee('Tienda Día &amp; Noche', false);
    }

    public function test_malicious_brand_text_is_escaped_in_settings_login_layout_and_receipt(): void
    {
        $name = '<script>alert("brand")</script>';
        $tagline = '<img src=x onerror=alert("tagline")>';
        $this->put(route('settings.update'), $this->payload(['name' => $name, 'tagline' => $tagline]))->assertRedirect(route('settings.edit'));

        foreach ([route('settings.edit'), route('products.index'), route('sales.show', $this->receipt())] as $url) {
            $this->get($url)->assertOk()->assertSee($name)->assertDontSee($name, false)->assertDontSee($tagline, false);
        }
        $this->app['auth']->logout();
        $this->get(route('login'))->assertOk()->assertSee($name)->assertSee($tagline)->assertDontSee($name, false)->assertDontSee($tagline, false);
    }

    public function test_branding_validation_rejects_malformed_colors_arrays_and_excessive_text_without_writing(): void
    {
        foreach ([
            [$this->payload(['name' => '   ']), ['name']],
            [$this->payload(['name' => str_repeat('x', 81), 'tagline' => str_repeat('x', 161)]), ['name', 'tagline']],
            [$this->payload(['name' => ['invalid'], 'tagline' => ['invalid'], 'color' => ['invalid']]), ['name', 'tagline', 'color']],
            [$this->payload(['color' => '#000000']), ['color']],
            [$this->payload(['color' => '#c45130; background:url(javascript:alert(1))']), ['color']],
            [$this->payload(['remove_logo' => 'unexpected']), ['remove_logo']],
        ] as [$payload, $errors]) {
            $this->from(route('settings.edit'))->put(route('settings.update'), $payload)
                ->assertRedirect(route('settings.edit'))->assertSessionHasErrors($errors);
        }
        // Redisplaying failed array input must remain a valid, escaped page.
        $this->put(route('settings.update'), $this->payload(['name' => ['invalid'], 'tagline' => ['invalid'], 'color' => ['invalid']]))->assertSessionHasErrors(['name', 'tagline', 'color']);
        $this->get(route('settings.edit'))->assertOk()->assertDontSee('@endif', false);
        $this->assertDatabaseCount('store_settings', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_logo_is_retained_when_omitted_replaced_after_commit_and_deleted_when_removed(): void
    {
        $this->put(route('settings.update'), $this->payload(['logo' => $this->png('first.png')]))->assertRedirect(route('settings.edit'));
        $firstPath = StoreSetting::findOrFail(1)->logo_path;
        Storage::disk('public')->assertExists($firstPath);
        $this->get(route('products.index'))->assertOk()->assertSee('/storage/'.$firstPath, false);
        $this->get(route('settings.edit'))->assertOk()->assertDontSee('@endif', false);

        $this->put(route('settings.update'), $this->payload(['name' => 'Sólo cambia el nombre']))->assertRedirect(route('settings.edit'));
        $this->assertSame($firstPath, StoreSetting::findOrFail(1)->logo_path);
        Storage::disk('public')->assertExists($firstPath);

        $this->put(route('settings.update'), $this->payload(['logo' => $this->png('second.png')]))->assertRedirect(route('settings.edit'));
        $secondPath = StoreSetting::findOrFail(1)->logo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        $this->assertCount(1, Storage::disk('public')->allFiles());

        $this->put(route('settings.update'), $this->payload(['remove_logo' => 1]))->assertRedirect(route('settings.edit'));
        $this->assertNull(StoreSetting::findOrFail(1)->logo_path);
        Storage::disk('public')->assertMissing($secondPath);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('store_settings', 1);
    }

    public function test_svg_code_disguised_as_png_and_oversized_raster_logos_are_rejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        foreach ([$this->upload('logo.svg', $svg), $this->upload('logo.png', '<?php echo 1;'), $this->upload('logo.png', $svg), $this->upload('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lX8AAAAASUVORK5CYII=').str_repeat('x', 2 * 1024 * 1024))] as $logo) {
            $this->put(route('settings.update'), $this->payload(['logo' => $logo]))->assertSessionHasErrors('logo');
        }
        $this->assertDatabaseCount('store_settings', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_conflicting_replace_and_remove_request_does_not_delete_existing_logo(): void
    {
        $this->put(route('settings.update'), $this->payload(['logo' => $this->png('existing.png')]))->assertRedirect(route('settings.edit'));
        $path = StoreSetting::findOrFail(1)->logo_path;

        $this->put(route('settings.update'), $this->payload(['name' => 'Should not be saved', 'logo' => $this->png('new.png'), 'remove_logo' => 1]))->assertSessionHasErrors('logo');
        $this->assertSame($path, StoreSetting::findOrFail(1)->logo_path);
        $this->assertSame('Almacén Las Flores', StoreSetting::findOrFail(1)->name);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }
}
