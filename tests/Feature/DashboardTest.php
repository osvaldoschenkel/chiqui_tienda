<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Argentina/Buenos_Aires'));
        $this->operator = User::factory()->create();
        $this->product = Product::create([
            'name' => 'Artículo de prueba', 'sku' => 'DASH-001', 'price_cents' => 99999,
            'cost_cents' => 100, 'stock' => 100, 'min_stock' => 2, 'active' => true,
        ]);
        $this->actingAs($this->operator);
    }

    private function storageDate(string $localDate): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate, 'America/Argentina/Buenos_Aires')->setTimezone(config('app.timezone'));
    }

    private function sale(string $date, int $total, string $payment = 'cash', int $quantity = 1, string $status = 'completed', ?Product $product = null): Sale
    {
        $requestId = (string) Str::uuid();
        $sale = Sale::create([
            'number' => 'DASH-'.$requestId, 'request_id' => $requestId, 'request_hash' => hash('sha256', $requestId),
            'payment_method' => $payment, 'total_cents' => $total, 'status' => $status,
            'user_id' => $this->operator->id, 'created_at' => $this->storageDate($date),
        ]);
        $product ??= $this->product;
        $sale->items()->create([
            'product_id' => $product->id, 'name' => 'Nombre histórico del artículo', 'sku' => 'SKU-HISTÓRICO',
            'quantity' => $quantity, 'unit_price_cents' => intdiv($total, $quantity), 'subtotal_cents' => $total,
        ]);

        return $sale;
    }

    private function purchase(string $date, int $total, string $status = 'completed'): Purchase
    {
        $requestId = (string) Str::uuid();

        return Purchase::create([
            'number' => 'DASH-P-'.$requestId, 'request_id' => $requestId, 'request_hash' => hash('sha256', $requestId),
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Proveedor dashboard'])->id,
            'payment_method' => 'cash', 'total_cents' => $total, 'status' => $status,
            'user_id' => $this->operator->id, 'created_at' => $this->storageDate($date),
        ]);
    }

    private function endpoint(array $query = []): string
    {
        return route('dashboard.data', $query);
    }

    public function test_dashboard_page_and_private_json_require_authentication(): void
    {
        $this->app['auth']->logout();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get($this->endpoint())->assertRedirect(route('login'));
        $this->getJson($this->endpoint())->assertUnauthorized();
    }

    public function test_empty_dashboard_has_zero_filled_days_and_all_payment_methods(): void
    {
        $response = $this->getJson($this->endpoint())->assertOk()->assertJsonStructure([
            'range' => ['preset', 'from', 'to', 'previous_from', 'previous_to', 'label', 'time_zone'],
            'summary' => ['sales_cents', 'sales_count', 'ticket_cents', 'units_sold', 'purchases_cents', 'customer_balance_cents', 'supplier_balance_cents', 'low_stock_count', 'previous_sales_cents', 'previous_sales_count', 'sales_change_percent'],
            'series' => [['date', 'label', 'sales_cents', 'previous_sales_cents', 'sales_count', 'previous_sales_count']],
            'payments' => [['key', 'label', 'total_cents', 'count']],
            'top_products', 'recent_sales', 'low_stock', 'updated_at', 'updated_label',
        ]);
        $data = $response->json();
        $this->assertSame('month', $data['range']['preset']);
        $this->assertSame('2026-10-01', $data['range']['from']);
        $this->assertSame('2026-10-08', $data['range']['to']);
        $this->assertCount(8, $data['series']);
        $this->assertSame(['cash', 'card', 'transfer', 'account'], array_column($data['payments'], 'key'));
        foreach ($data['summary'] as $key => $value) {
            if ($key === 'sales_change_percent') {
                $this->assertNull($value);
            } else {
                $this->assertSame(0, $value, $key);
            }
        }
        foreach ($data['series'] as $day) {
            $this->assertSame(0, $day['sales_cents']);
            $this->assertSame(0, $day['previous_sales_cents']);
            $this->assertSame(0, $day['sales_count']);
            $this->assertSame(0, $day['previous_sales_count']);
        }
        foreach ($data['payments'] as $payment) {
            $this->assertSame(0, $payment['total_cents']);
            $this->assertSame(0, $payment['count']);
        }
        $this->assertSame([], $data['top_products']);
        $this->assertSame([], $data['recent_sales']);
        $this->assertSame([], $data['low_stock']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_custom_local_days_include_midnight_and_late_sales_but_exclude_cancelled_and_outside_rows(): void
    {
        $before = $this->sale('2026-10-02 23:59:59', 300);
        $first = $this->sale('2026-10-03 00:00:00', 101);
        $second = $this->sale('2026-10-03 23:59:59', 200, 'card', 2);
        $third = $this->sale('2026-10-04 12:00:00', 202, 'transfer', 2);
        $last = $this->sale('2026-10-04 23:59:59', 300, 'account', 3);
        $after = $this->sale('2026-10-05 00:00:00', 90000);
        $cancelled = $this->sale('2026-10-04 10:00:00', 90000, 'account', 100, 'cancelled');
        $this->sale('2026-10-01 00:00:00', 100);
        $this->sale('2026-10-02 12:00:00', 90000, 'cash', 100, 'cancelled');
        $this->purchase('2026-10-03 00:00:00', 12300);
        $this->purchase('2026-10-04 23:59:59', 90000, 'cancelled');
        $this->purchase('2026-10-05 00:00:00', 90000);

        $query = ['preset' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-04'];
        $data = $this->getJson($this->endpoint($query))->assertOk()->json();
        $this->assertSame('America/Argentina/Buenos_Aires', $data['range']['time_zone']);
        $this->assertSame('2026-10-01', $data['range']['previous_from']);
        $this->assertSame('2026-10-02', $data['range']['previous_to']);
        foreach (['sales_cents' => 803, 'sales_count' => 4, 'ticket_cents' => 201, 'units_sold' => 8, 'purchases_cents' => 12300, 'previous_sales_cents' => 400, 'previous_sales_count' => 2] as $key => $value) {
            $this->assertSame($value, $data['summary'][$key], $key);
        }
        $this->assertSame(100.8, $data['summary']['sales_change_percent']);
        $this->assertSame([301, 502], array_column($data['series'], 'sales_cents'));
        $this->assertSame([100, 300], array_column($data['series'], 'previous_sales_cents'));
        $this->assertSame([2, 2], array_column($data['series'], 'sales_count'));
        $this->assertSame([101, 200, 202, 300], array_column($data['payments'], 'total_cents'));
        $this->assertSame([1, 1, 1, 1], array_column($data['payments'], 'count'));
        $this->assertSame(8, $data['top_products'][0]['quantity']);
        $this->assertSame(803, $data['top_products'][0]['total_cents']);
        $this->assertSame([$last->number, $third->number, $second->number, $first->number], array_column($data['recent_sales'], 'number'));
        $this->assertSame('04/10 · 23:59', $data['recent_sales'][0]['date']);
        foreach ([$before, $after, $cancelled] as $excluded) {
            $this->assertNotContains($excluded->number, array_column($data['recent_sales'], 'number'));
        }

        $page = $this->get(route('dashboard', $query))->assertOk();
        $this->assertSame($data, $page->viewData('dashboard'));
        $page->assertSee(Money::format(803))->assertSee(Money::format(201))->assertSee($last->number)->assertDontSee($cancelled->number);
    }

    public function test_all_presets_compare_equal_length_adjacent_periods_and_ignore_unrelated_custom_parameters(): void
    {
        $ranges = [
            'today' => ['2026-10-08', '2026-10-08', '2026-10-07', '2026-10-07', 1],
            'yesterday' => ['2026-10-07', '2026-10-07', '2026-10-06', '2026-10-06', 1],
            '7d' => ['2026-10-02', '2026-10-08', '2026-09-25', '2026-10-01', 7],
            '30d' => ['2026-09-09', '2026-10-08', '2026-08-10', '2026-09-08', 30],
            'month' => ['2026-10-01', '2026-10-08', '2026-09-23', '2026-09-30', 8],
        ];
        foreach ($ranges as $preset => [$from, $to, $previousFrom, $previousTo, $days]) {
            $data = $this->getJson($this->endpoint(['preset' => $preset, 'from' => 'invalid', 'to' => 'invalid']))->assertOk()->json();
            $this->assertSame($preset, $data['range']['preset']);
            $this->assertSame($from, $data['range']['from']);
            $this->assertSame($to, $data['range']['to']);
            $this->assertSame($previousFrom, $data['range']['previous_from']);
            $this->assertSame($previousTo, $data['range']['previous_to']);
            $this->assertCount($days, $data['series']);
            $this->assertSame($from, $data['series'][0]['date']);
            $this->assertSame($to, $data['series'][$days - 1]['date']);
        }
    }

    public function test_zero_comparison_baseline_stays_null_and_complete_decline_is_minus_one_hundred_percent(): void
    {
        $this->sale('2026-10-08 10:00:00', 2003);
        $today = $this->getJson($this->endpoint(['preset' => 'today']))->assertOk()->json();
        $this->assertSame(2003, $today['summary']['sales_cents']);
        $this->assertSame(0, $today['summary']['previous_sales_cents']);
        $this->assertNull($today['summary']['sales_change_percent']);

        $this->sale('2026-10-04 10:00:00', 10000);
        $empty = $this->getJson($this->endpoint(['preset' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()->json();
        $this->assertSame(0, $empty['summary']['sales_cents']);
        $this->assertSame(10000, $empty['summary']['previous_sales_cents']);
        $this->assertEquals(-100.0, $empty['summary']['sales_change_percent']);
    }

    public function test_invalid_reversed_future_and_excessively_long_dates_fail_validation(): void
    {
        foreach ([
            [['preset' => 'unknown'], ['preset']],
            [['preset' => ['today']], ['preset']],
            [['preset' => 'custom'], ['from', 'to']],
            [['preset' => 'custom', 'from' => '2026-02-30', 'to' => '2026-10-08'], ['from']],
            [['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-07'], ['to']],
            [['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-09'], ['to']],
            [['preset' => 'custom', 'from' => '1999-12-31', 'to' => '2000-01-01'], ['from']],
            [['preset' => 'custom', 'from' => '2025-10-07', 'to' => '2026-10-08'], ['to']],
        ] as [$query, $errors]) {
            $this->getJson($this->endpoint($query))->assertUnprocessable()->assertJsonValidationErrors($errors);
        }
        $this->from(route('dashboard'))->get(route('dashboard', ['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-07']))
            ->assertRedirect(route('dashboard'))->assertSessionHasErrors('to');
    }

    public function test_full_366_day_range_is_supported_and_every_date_is_represented(): void
    {
        $this->sale('2025-10-08 00:00:00', 1001);
        $this->sale('2026-10-08 23:59:59', 1002);
        $data = $this->getJson($this->endpoint(['preset' => 'custom', 'from' => '2025-10-08', 'to' => '2026-10-08']))->assertOk()->json();
        $this->assertCount(366, $data['series']);
        $this->assertSame('2025-10-08', $data['series'][0]['date']);
        $this->assertSame('2026-10-08', $data['series'][365]['date']);
        $this->assertSame(2003, $data['summary']['sales_cents']);
        $this->assertSame(1002, $data['summary']['ticket_cents']);
        $this->assertSame(2, $data['summary']['sales_count']);
        $this->assertSame(0, $data['series'][1]['sales_cents']);
    }

    public function test_current_accounts_and_active_low_stock_are_independent_of_the_selected_historical_period(): void
    {
        $customer = Customer::create(['name' => 'Cliente actual']);
        $supplier = Supplier::create(['name' => 'Proveedor actual']);
        foreach ([[$customer->id, null, 'debit', 10001], [$customer->id, null, 'credit', 4001], [null, $supplier->id, 'debit', 5000], [null, $supplier->id, 'credit', 1000]] as [$customerId, $supplierId, $direction, $amount]) {
            LedgerEntry::create([
                'customer_id' => $customerId, 'supplier_id' => $supplierId, 'direction' => $direction,
                'amount_cents' => $amount, 'description' => 'Movimiento posterior al período',
                'date' => $this->storageDate('2026-10-08 10:00:00'), 'user_id' => $this->operator->id,
            ]);
        }
        $low = Product::create(['name' => 'Reposición actual', 'sku' => 'LOW-NOW', 'price_cents' => 100, 'stock' => 2, 'min_stock' => 2, 'active' => true]);
        Product::create(['name' => 'Producto inactivo', 'sku' => 'LOW-INACTIVE', 'price_cents' => 100, 'stock' => 0, 'min_stock' => 2, 'active' => false]);
        $data = $this->getJson($this->endpoint(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-02']))->assertOk()->json();
        $this->assertSame(0, $data['summary']['sales_cents']);
        $this->assertSame(6000, $data['summary']['customer_balance_cents']);
        $this->assertSame(4000, $data['summary']['supplier_balance_cents']);
        $this->assertSame(1, $data['summary']['low_stock_count']);
        $this->assertSame([$low->id], array_column($data['low_stock'], 'id'));
        $this->assertSame(route('products.edit', $low), $data['low_stock'][0]['url']);
    }

    public function test_top_products_use_recorded_revenue_while_current_catalog_names_and_links_remain_valid(): void
    {
        $other = Product::create(['name' => 'Otro artículo', 'sku' => 'OTHER-QA', 'price_cents' => 999999, 'stock' => 20, 'active' => true]);
        $this->sale('2026-10-08 10:00:00', 300, 'cash', 3);
        $this->sale('2026-10-08 10:01:00', 2200, 'cash', 2);
        $this->sale('2026-10-08 10:02:00', 4000, 'cash', 4, 'completed', $other);
        $this->sale('2026-10-08 10:03:00', 999999, 'cash', 100, 'cancelled');
        $this->sale('2026-10-07 10:00:00', 999999, 'cash', 100);
        $this->product->update(['name' => 'Nombre actual del catálogo', 'sku' => 'CURRENT-QA', 'price_cents' => 1000000]);

        $data = $this->getJson($this->endpoint(['preset' => 'today']))->assertOk()->json();
        $this->assertSame(6500, $data['summary']['sales_cents']);
        $this->assertSame(9, $data['summary']['units_sold']);
        $this->assertSame([$this->product->id, $other->id], array_column($data['top_products'], 'id'));
        $this->assertSame([5, 4], array_column($data['top_products'], 'quantity'));
        $this->assertSame([2500, 4000], array_column($data['top_products'], 'total_cents'));
        $this->assertSame('Nombre actual del catálogo', $data['top_products'][0]['name']);
        $this->assertSame('CURRENT-QA', $data['top_products'][0]['sku']);
        $this->assertSame(route('products.edit', $this->product), $data['top_products'][0]['url']);
    }

    public function test_historical_daylight_saving_transition_keeps_calendar_days_and_previous_period_aligned(): void
    {
        $this->sale('2007-12-29 23:59:59', 50);
        // Argentina skipped local midnight on this historical date; the local day starts at 01:00.
        $this->sale('2007-12-30 01:00:00', 100);
        $this->sale('2007-12-31 23:59:59', 200);
        $this->sale('2008-01-01 00:00:00', 90000);
        $data = $this->getJson($this->endpoint(['preset' => 'custom', 'from' => '2007-12-30', 'to' => '2007-12-31']))->assertOk()->json();

        $this->assertCount(2, $data['series']);
        $this->assertSame(['2007-12-30', '2007-12-31'], array_column($data['series'], 'date'));
        $this->assertSame([100, 200], array_column($data['series'], 'sales_cents'));
        $this->assertSame([0, 50], array_column($data['series'], 'previous_sales_cents'));
        $this->assertSame('2007-12-28', $data['range']['previous_from']);
        $this->assertSame('2007-12-29', $data['range']['previous_to']);
        $this->assertSame(300, $data['summary']['sales_cents']);
    }

    public function test_day_boundaries_also_work_when_timestamps_are_stored_in_the_store_timezone(): void
    {
        config(['app.timezone' => 'America/Argentina/Buenos_Aires']);
        $this->sale('2026-10-07 23:59:59', 50);
        $this->sale('2026-10-08 00:00:00', 100);
        $this->sale('2026-10-08 23:59:59', 200);
        $this->sale('2026-10-09 00:00:00', 90000);
        $data = $this->getJson($this->endpoint(['preset' => 'today']))->assertOk()->json();

        $this->assertSame(300, $data['summary']['sales_cents']);
        $this->assertSame(2, $data['summary']['sales_count']);
        $this->assertSame(50, $data['summary']['previous_sales_cents']);
        $this->assertSame(300, $data['series'][0]['sales_cents']);
        $this->assertSame('08/10 · 23:59', $data['recent_sales'][0]['date']);
    }
}
