<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\FiscalInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShippingQuote;
use App\Models\User;
use App\Services\ArcaClient;
use App\Services\ArcaInvoices;
use App\Services\IntegrationSettings;
use App\Services\OnlineOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OnlineCommerceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Equipo', 'sku' => (string) Str::uuid(), 'price_cents' => 12100, 'stock' => 10, 'min_stock' => 1, 'active' => true, 'weight_grams' => 200, 'height_cm' => 4, 'width_cm' => 10, 'length_cm' => 20, 'vat_rate' => 21]);
    }

    private function order(string $method = 'mercadopago', int $shipping = 0): CustomerOrder
    {
        $u = $this->user();
        $p = $this->product();
        $this->actingAs($u)->withSession(['shop.cart' => [$p->id => 2]])->post(route('shop.orders.store'), ['request_id' => (string) Str::uuid(), 'delivery_method' => 'pickup', 'payment_method' => $method, 'billing' => ['name' => $u->name, 'document' => '12345678', 'condition' => 5]])->assertRedirect();
        $o = CustomerOrder::latest('id')->firstOrFail();
        if ($shipping) {
            $o->update(['shipping_cents' => $shipping, 'total_cents' => $o->total_cents + $shipping]);
        }

        return $o;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.mp_token' => 'TEST-token', 'commerce.mp_webhook_secret' => 'webhook-secret', 'commerce.cod_enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_brand_crud_rejects_duplicate_and_deletion_with_products(): void
    {
        $a = $this->user('admin');
        $this->actingAs($a)->post(route('brands.store'), ['name' => 'Marca', 'active' => 1])->assertRedirect();
        $b = Brand::sole();
        $this->post(route('brands.store'), ['name' => 'Marca', 'active' => 1])->assertSessionHasErrors('name');
        $p = $this->product();
        $p->update(['brand_id' => $b->id]);
        $this->delete(route('brands.destroy', $b))->assertSessionHasErrors('brand');
        $this->put(route('brands.update', $b), ['name' => 'Marca', 'active' => 0])->assertRedirect();
        $this->assertFalse($b->fresh()->active);
        $this->actingAs($this->user())->get(route('brands.index'))->assertForbidden();
    }

    public function test_online_payment_is_idempotent_and_books_sale_without_second_stock_decrement(): void
    {
        $o = $this->order();
        $payment = ['id' => 123, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 242, 'status' => 'approved'];
        $service = app(OnlineOrders::class);
        $this->assertTrue($service->applyPayment($payment));
        $this->assertTrue($service->applyPayment($payment));
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(8, Product::first()->stock);
        $this->assertSame('confirmed', $o->fresh()->payment_status);
        $this->assertNotNull($o->fresh()->sale_id);
        $this->assertSame(24200, Sale::sole()->total_cents);
    }

    public function test_late_payment_does_not_reopen_cancelled_order_or_consume_stock(): void
    {
        $o = $this->order();
        $service = app(OnlineOrders::class);
        $service->cancel($o, $o->user_id);
        $service->cancel($o, $o->user_id);
        $service->applyPayment(['id' => 123, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 242, 'status' => 'approved']);
        $this->assertSame(10, Product::first()->stock);
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertNotNull($o->fresh()->payment_review);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_amount_mismatch_never_credits_order(): void
    {
        $o = $this->order();
        try {
            app(OnlineOrders::class)->applyPayment(['id' => 1, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 1, 'status' => 'approved']);
            $this->fail('Must reject payment');
        } catch (ValidationException $e) {
            $this->assertSame('pending', $o->fresh()->payment_status);
            $this->assertDatabaseCount('sales', 0);
        }
    }

    public function test_refund_is_recorded_without_automatically_returning_shipped_stock(): void
    {
        $o = $this->order();
        $p = ['id' => 123, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 242, 'status' => 'approved'];
        app(OnlineOrders::class)->applyPayment($p);
        $p['status'] = 'refunded';
        app(OnlineOrders::class)->applyPayment($p);
        $this->assertSame('refunded', $o->fresh()->payment_status);
        $this->assertSame('cancelled', Sale::sole()->status);
        $this->assertSame(8, Product::first()->stock);
    }

    public function test_cod_can_prepare_before_payment_but_cannot_deliver_until_collected(): void
    {
        $o = $this->order('cod');
        $this->actingAs($this->user('admin'))->patch(route('shop.admin.orders.update', $o), ['status' => 'preparing', 'payment_status' => 'pending'])->assertRedirect();
        $this->patch(route('shop.admin.orders.update', $o), ['status' => 'shipped', 'payment_status' => 'pending'])->assertRedirect();
        $this->patch(route('shop.admin.orders.update', $o), ['status' => 'delivered', 'payment_status' => 'pending'])->assertSessionHasErrors('payment_status');
        $this->patch(route('shop.admin.orders.update', $o), ['status' => 'delivered', 'payment_status' => 'confirmed'])->assertRedirect();
        $this->assertSame(8, Product::first()->stock);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_mp_cannot_be_confirmed_manually(): void
    {
        $o = $this->order();
        $this->actingAs($this->user('admin'))->patch(route('shop.admin.orders.update', $o), ['status' => 'preparing', 'payment_status' => 'confirmed'])->assertSessionHasErrors('payment_status');
        $this->assertSame('pending', $o->fresh()->payment_status);
    }

    public function test_preference_is_reused_and_cannot_be_opened_by_another_customer(): void
    {
        $o = $this->order('mercadopago', 1210);
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'preference-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout/test'])]);
        $this->post(route('commerce.pay', $o))->assertRedirect('https://sandbox.mercadopago.com.ar/checkout/test');
        $this->post(route('commerce.pay', $o))->assertRedirect();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['shipments']['cost'] === 12.1 && $r['items'][0]['unit_price'] === 121);
        $this->actingAs($this->user())->post(route('commerce.pay', $o))->assertNotFound();
    }

    public function test_signed_webhook_uses_provider_payment_and_denies_tampering(): void
    {
        $o = $this->order();
        $ts = (string) time();
        $signature = hash_hmac('sha256', 'id:123;request-id:req;ts:'.$ts.';', 'webhook-secret');
        Http::fake(['api.mercadopago.com/v1/payments/123' => Http::response(['id' => 123, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 242, 'status' => 'approved'])]);
        $headers = ['x-request-id' => 'req', 'x-signature' => 'ts='.$ts.',v1='.$signature];
        $this->postJson(route('commerce.mp.webhook').'?data.id=123', [], $headers)->assertOk();
        $this->postJson(route('commerce.mp.webhook').'?data.id=124', [], $headers)->assertUnauthorized();
        $this->assertSame('confirmed', $o->fresh()->payment_status);
    }

    public function test_quote_is_bound_to_user_prices_measures_and_destination(): void
    {
        config(['commerce.zip_token' => 'token', 'commerce.zip_secret' => 'secret', 'commerce.zip_account' => 1, 'commerce.zip_origin' => 2]);
        $u = $this->user();
        $p = $this->product();
        Http::fake(['api.zipnova.com.ar/v2/shipments/quote' => Http::response(['all_results' => [['selectable' => true, 'carrier' => ['id' => 3, 'name' => 'Correo'], 'service_type' => ['code' => 'standard_delivery', 'name' => 'Domicilio'], 'logistic_type' => 'carrier_dropoff', 'amounts' => ['price_incl_tax' => 12.10]]]])]);
        $this->actingAs($u)->withSession(['shop.cart' => [$p->id => 2]])->postJson(route('commerce.shipping.quote'), ['postcode' => '1000', 'city' => 'CABA', 'state' => 'CABA'])->assertOk();
        $q = ShippingQuote::sole();
        $p->update(['price_cents' => 12200]);
        $this->post(route('shop.orders.store'), ['request_id' => (string) Str::uuid(), 'payment_method' => 'manual', 'delivery_method' => 'zipnova', 'shipping_option' => $q->id.':0', 'address' => ['name' => 'Cliente', 'phone' => '1111', 'street' => 'Calle', 'number' => '1', 'city' => 'CABA', 'state' => 'CABA', 'postcode' => '1000'], 'billing' => ['name' => 'Cliente', 'document' => '12345678', 'condition' => 5]])->assertSessionHasErrors('shipping_option');
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_shipping_uncertain_response_blocks_second_creation(): void
    {
        config(['commerce.zip_token' => 'token', 'commerce.zip_secret' => 'secret', 'commerce.zip_account' => 1, 'commerce.zip_origin' => 2]);
        $o = $this->order('cod');
        $o->update(['delivery_method' => 'zipnova', 'shipping_selection' => ['account_id' => 1, 'origin_id' => 2, 'destination' => []], 'address' => ['name' => 'Cliente', 'phone' => '111', 'street' => 'Calle', 'number' => '1', 'city' => 'CABA', 'state' => 'CABA', 'postcode' => '1000']]);
        Http::fake(['api.zipnova.com.ar/v2/shipments' => Http::response([], 503)]);
        $this->actingAs($this->user('admin'))->post(route('commerce.shipment', $o))->assertSessionHasErrors('shipping');
        $this->post(route('commerce.shipment', $o))->assertSessionHasErrors('shipping');
        Http::assertSentCount(1);
        $this->assertSame('unknown', $o->fresh()->shipment_state);
    }

    public function test_fiscal_payload_preserves_final_price_and_splits_included_vat(): void
    {
        $o = $this->order('manual', 1210);
        $arca = app(ArcaInvoices::class);
        $detail = $arca->payload($o, 6, 1, 1)['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(254.1, $detail['ImpTotal']);
        $this->assertSame(210, $detail['ImpNeto']);
        $this->assertSame(44.1, $detail['ImpIVA']);
        $c = $arca->payload($o, 11, 1, 1)['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(254.1, $c['ImpNeto']);
        $this->assertSame(0, $c['ImpIVA']);
        $this->assertArrayNotHasKey('Iva', $c);
        $this->assertSame(5, $detail['CondicionIVAReceptorId']);
    }

    private function configureArca(): void
    {
        config(['commerce.arca_cuit' => '20123456786', 'commerce.arca_pos' => 1, 'commerce.arca_name' => 'Emisor', 'commerce.arca_address' => 'Calle 1', 'commerce.arca_activity_date' => '2020-01-01', 'commerce.arca_certificate' => '/test/certificate', 'commerce.arca_key' => '/test/key']);
    }

    public function test_invoice_retry_consults_same_number_and_does_not_authorize_twice(): void
    {
        $this->configureArca();
        $o = $this->order('manual');
        $o->update(['payment_status' => 'confirmed']);
        $client = \Mockery::mock(ArcaClient::class);
        $client->shouldReceive('last')->once()->andReturn(0);
        $client->shouldReceive('consult')->with(1, 11, 1)->twice()->andReturn(null, ['DocNro' => 12345678, 'ImpTotal' => 242, 'CbteFch' => now('America/Argentina/Buenos_Aires')->format('Ymd'), 'CodAutorizacion' => '12345678901234', 'FchVto' => now()->addDays(10)->format('Ymd')]);
        $client->shouldReceive('authorize')->once()->andThrow(new \RuntimeException('Connection lost'));
        $this->app->instance(ArcaClient::class, $client);
        try {
            app(ArcaInvoices::class)->issue($o);
        } catch (ValidationException $e) {
        }
        $this->assertDatabaseCount('fiscal_invoices', 1);
        $i = app(ArcaInvoices::class)->issue($o->fresh());
        $this->assertSame('authorized', $i->status);
        $this->assertSame(1, $i->voucher_number);
    }

    public function test_authorized_invoice_pdf_is_private_and_contains_cae(): void
    {
        $this->configureArca();
        $o = $this->order('manual');
        $o->update(['payment_status' => 'confirmed']);
        $request = app(ArcaInvoices::class)->payload($o, 11, 1, 1);
        $request['issuer'] = ['name' => 'Emisor', 'cuit' => '20123456786', 'condition' => 'monotributo', 'address' => 'Calle 1', 'activity_date' => '2020-01-01'];
        FiscalInvoice::create(['customer_order_id' => $o->id, 'issuer_cuit' => '20123456786', 'environment' => 'homologation', 'point_of_sale' => 1, 'voucher_type' => 11, 'voucher_number' => 1, 'status' => 'authorized', 'request' => $request, 'cae' => '12345678901234', 'cae_expires_at' => now()->addDays(10)]);
        $this->get(route('commerce.invoice.download', $o))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->user())->get(route('commerce.invoice.download', $o))->assertNotFound();
    }

    public function test_secrets_are_encrypted_and_blank_values_preserve_them(): void
    {
        IntegrationSettings::create(['id' => 1, 'values' => ['mp_token' => 'private-token']]);
        $this->assertStringNotContainsString('private-token', DB::table('integration_settings')->value('values'));
        $this->actingAs($this->user('admin'))->put(route('integrations.update'), ['mp_token' => '', 'cod_enabled' => 0, 'cod_delivery_enabled' => 0, 'arca_environment' => 'homologation', 'arca_condition' => 'monotributo'])->assertRedirect();
        $this->assertSame('private-token', IntegrationSettings::get('mp_token'));
        $this->get(route('integrations.edit'))->assertOk()->assertDontSee('private-token');
    }

    public function test_zero_and_mixed_vat_rates_preserve_total(): void
    {
        $o = $this->order('manual');
        $items = $o->items;
        $items = [array_replace($items[0], ['quantity' => 1, 'unit_price_cents' => 10000, 'vat_rate' => 0]), array_replace($items[0], ['quantity' => 1, 'unit_price_cents' => 11050, 'vat_rate' => 10.5]), array_replace($items[0], ['quantity' => 1, 'unit_price_cents' => 12700, 'vat_rate' => 27])];
        $o->update(['items' => $items, 'total_cents' => 33750]);
        $detail = app(ArcaInvoices::class)->payload($o, 6, 1, 1)['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(100, $detail['ImpOpEx']);
        $this->assertSame(200, $detail['ImpNeto']);
        $this->assertSame(37.5, $detail['ImpIVA']);
        $this->assertSame(337.5, $detail['ImpTotal']);
    }

    public function test_quote_checkout_charges_shipping_once_and_reserves_stock_once(): void
    {
        config(['commerce.zip_token' => 'token', 'commerce.zip_secret' => 'secret', 'commerce.zip_account' => 1, 'commerce.zip_origin' => 2]);
        $user = $this->user();
        $p = $this->product();
        Http::fake(['api.zipnova.com.ar/v2/shipments/quote' => Http::response(['all_results' => [['selectable' => true, 'carrier' => ['id' => 3, 'name' => 'Correo'], 'service_type' => ['code' => 'standard_delivery', 'name' => 'Domicilio'], 'logistic_type' => 'carrier_dropoff', 'amounts' => ['price_incl_tax' => 12.10]]]])]);
        $this->actingAs($user)->withSession(['shop.cart' => [$p->id => 2]])->postJson(route('commerce.shipping.quote'), ['postcode' => '1000', 'city' => 'CABA', 'state' => 'CABA'])->assertOk();
        $quote = ShippingQuote::sole();
        $payload = ['request_id' => (string) Str::uuid(), 'payment_method' => 'mercadopago', 'delivery_method' => 'zipnova', 'shipping_option' => $quote->id.':0', 'address' => ['name' => 'Cliente', 'phone' => '1111', 'street' => 'Calle', 'number' => '1', 'city' => 'CABA', 'state' => 'CABA', 'postcode' => '1000'], 'billing' => ['name' => 'Cliente', 'document' => '12345678', 'condition' => 5]];
        $this->post(route('shop.orders.store'), $payload)->assertRedirect();
        $this->post(route('shop.orders.store'), $payload)->assertRedirect();
        $o = CustomerOrder::sole();
        $this->assertSame(25410, $o->total_cents);
        $this->assertSame(1210, $o->shipping_cents);
        $this->assertSame(8, $p->fresh()->stock);
        app(OnlineOrders::class)->applyPayment(['id' => 123, 'external_reference' => (string) $o->id, 'currency_id' => 'ARS', 'transaction_amount' => 254.1, 'status' => 'approved']);
        $this->assertSame(1210, (int) Sale::sole()->shipping_cents);
        $this->assertSame(8,$p->fresh()->stock);
    }
}
