<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DashboardStatistics
{
    private const TIME_ZONE = 'America/Argentina/Buenos_Aires';
    private const PAYMENT_LABELS = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'account' => 'Cuenta corriente',
    ];

    /** All monetary values are integer cents; date ranges follow the store's local calendar. */
    public function generate(array $filters = []): array
    {
        $now = CarbonImmutable::now(self::TIME_ZONE);
        [$preset, $from, $to, $label] = $this->range($filters, $now->startOfDay());
        $days = $this->calendarDays($from, $to);
        $previousFrom = $from->subDays($days);
        $previousTo = $from->subDay();
        [$startsAt, $endsAt] = $this->storageBounds($from, $to);

        $current = $this->dailySales($from, $days);
        $previous = $this->dailySales($previousFrom, $days);
        $series = [];
        $salesCents = 0;
        $salesCount = 0;
        $previousSalesCents = 0;
        $previousSalesCount = 0;
        for ($index = 0; $index < $days; $index++) {
            $date = $from->addDays($index);
            $priorDate = $previousFrom->addDays($index)->toDateString();
            $daily = $current[$date->toDateString()];
            $prior = $previous[$priorDate];
            $salesCents += $daily['sales_cents'];
            $salesCount += $daily['sales_count'];
            $previousSalesCents += $prior['sales_cents'];
            $previousSalesCount += $prior['sales_count'];
            $series[] = [
                'date' => $date->toDateString(), 'label' => $date->format('d/m'),
                'sales_cents' => $daily['sales_cents'], 'previous_sales_cents' => $prior['sales_cents'],
                'sales_count' => $daily['sales_count'], 'previous_sales_count' => $prior['sales_count'],
            ];
        }

        $paymentsByKey = $this->sales($startsAt, $endsAt)
            ->select('payment_method')->selectRaw('SUM(total_cents) AS total_cents, COUNT(*) AS sale_count')
            ->groupBy('payment_method')->get()->keyBy('payment_method');
        $payments = [];
        foreach (self::PAYMENT_LABELS as $key => $paymentLabel) {
            $row = $paymentsByKey->get($key);
            $payments[] = ['key' => $key, 'label' => $paymentLabel, 'total_cents' => (int) ($row?->total_cents ?? 0), 'count' => (int) ($row?->sale_count ?? 0)];
        }

        $lowStockQuery = DB::table('products')->where('active', true)->whereColumn('stock', '<=', 'min_stock');
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStock = $lowStockQuery->orderBy('stock')->orderBy('name')->orderBy('id')->limit(5)
            ->get(['id', 'name', 'sku', 'stock', 'min_stock'])->map(fn ($product) => [
                'id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'stock' => (int) $product->stock, 'min_stock' => (int) $product->min_stock,
                'url' => route('products.edit', $product->id),
            ])->all();

        $items = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')->where('sales.created_at', '>=', $startsAt)->where('sales.created_at', '<', $endsAt);
        $unitsSold = (int) (clone $items)->sum('sale_items.quantity');
        $topProducts = $items->join('products', 'products.id', '=', 'sale_items.product_id')
            ->select('products.id', 'products.name', 'products.sku')
            ->selectRaw('SUM(sale_items.quantity) AS quantity, SUM(sale_items.subtotal_cents) AS total_cents')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('quantity')->orderByDesc('total_cents')->orderBy('products.id')->limit(5)->get()
            ->map(fn ($product) => [
                'id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'quantity' => (int) $product->quantity, 'total_cents' => (int) $product->total_cents,
                'url' => route('products.edit', $product->id),
            ])->all();

        $recentSales = $this->sales($startsAt, $endsAt)->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->orderByDesc('sales.created_at')->orderByDesc('sales.id')->limit(6)
            ->get(['sales.id', 'sales.number', 'sales.created_at', 'sales.total_cents', 'sales.payment_method', 'customers.name as customer_name'])
            ->map(fn ($sale) => [
                'number' => $sale->number, 'customer' => $sale->customer_name ?? 'Consumidor final',
                'date' => CarbonImmutable::parse($sale->created_at, config('app.timezone'))->setTimezone(self::TIME_ZONE)->format('d/m · H:i'),
                'total_cents' => (int) $sale->total_cents,
                'payment_label' => self::PAYMENT_LABELS[$sale->payment_method] ?? $sale->payment_method,
                'url' => route('sales.show', $sale->id),
            ])->all();

        return [
            'range' => [
                'preset' => $preset, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'previous_from' => $previousFrom->toDateString(), 'previous_to' => $previousTo->toDateString(),
                'label' => $label, 'time_zone' => self::TIME_ZONE,
            ],
            'summary' => [
                'sales_cents' => $salesCents, 'sales_count' => $salesCount,
                'ticket_cents' => $salesCount > 0 ? intdiv($salesCents, $salesCount) + (($salesCents % $salesCount >= intdiv($salesCount, 2) + $salesCount % 2) ? 1 : 0) : 0,
                'units_sold' => $unitsSold,
                'purchases_cents' => (int) DB::table('purchases')->where('status', 'completed')->where('created_at', '>=', $startsAt)->where('created_at', '<', $endsAt)->sum('total_cents'),
                'customer_balance_cents' => $this->balance('customer_id'),
                'supplier_balance_cents' => $this->balance('supplier_id'),
                'low_stock_count' => (int) $lowStockCount,
                'previous_sales_cents' => $previousSalesCents, 'previous_sales_count' => $previousSalesCount,
                'sales_change_percent' => $previousSalesCents > 0 ? round(($salesCents - $previousSalesCents) / $previousSalesCents * 100, 1) : null,
            ],
            'series' => $series, 'payments' => $payments, 'top_products' => $topProducts,
            'recent_sales' => $recentSales, 'low_stock' => $lowStock,
            'updated_at' => $now->toIso8601String(), 'updated_label' => $now->format('H:i'),
        ];
    }

    /** @return array{string, CarbonImmutable, CarbonImmutable, string} */
    private function range(array $filters, CarbonImmutable $today): array
    {
        $preset = Validator::make(['preset' => $filters['preset'] ?? 'month'], [
            'preset' => ['required', 'string', 'in:today,yesterday,7d,30d,month,custom'],
        ], [
            'preset.required' => 'Seleccioná un período.', 'preset.string' => 'El período debe ser una opción válida.',
            'preset.in' => 'El período seleccionado no es válido.',
        ])->validate()['preset'];
        if ($preset === 'custom') {
            $dates = Validator::make($filters, [
                'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today->toDateString()],
            ], [
                'from.required' => 'Ingresá la fecha inicial.', 'to.required' => 'Ingresá la fecha final.',
                'from.date_format' => 'La fecha inicial debe tener el formato AAAA-MM-DD.',
                'to.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD.',
                'from.after_or_equal' => 'La fecha inicial debe ser el 01/01/2000 o posterior.',
                'to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
                'to.before_or_equal' => 'La fecha final no puede ser posterior a hoy.',
            ])->validate();
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $dates['from'], self::TIME_ZONE);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $dates['to'], self::TIME_ZONE);
            if ($this->calendarDays($from, $to) > 366) {
                throw ValidationException::withMessages(['to' => 'El período puede incluir como máximo 366 días.']);
            }
            return [$preset, $from, $to, $from->format('d/m/Y').' al '.$to->format('d/m/Y')];
        }

        return match ($preset) {
            'today' => [$preset, $today, $today, 'Hoy'],
            'yesterday' => [$preset, $today->subDay(), $today->subDay(), 'Ayer'],
            '7d' => [$preset, $today->subDays(6), $today, 'Últimos 7 días'],
            '30d' => [$preset, $today->subDays(29), $today, 'Últimos 30 días'],
            default => [$preset, $today->startOfMonth(), $today, 'Este mes'],
        };
    }

    /** Count dates rather than elapsed time: historical BA changes can skip local midnight. */
    private function calendarDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $firstDate = CarbonImmutable::createFromFormat('!Y-m-d', $from->toDateString(), 'UTC');
        $lastDate = CarbonImmutable::createFromFormat('!Y-m-d', $to->toDateString(), 'UTC');
        return (int) $firstDate->diff($lastDate)->days + 1;
    }

    /** SQL calendar joins avoid timezone-table dependencies and leave timestamp filters indexable. */
    private function dailySales(CarbonImmutable $from, int $days): array
    {
        $calendar = [];
        for ($index = 0; $index < $days; $index++) {
            $date = $from->addDays($index);
            [$start, $end] = $this->storageBounds($date, $date);
            $calendar[] = [$date->toDateString(), $start, $end];
        }
        $result = [];
        // Keep below SQLite's older bind/compound-select limits, including a full 366-day range.
        foreach (array_chunk($calendar, 128) as $chunk) {
            $selects = [];
            $bindings = [];
            foreach ($chunk as $day) {
                $selects[] = 'SELECT ? AS bucket_date, ? AS starts_at, ? AS ends_at';
                array_push($bindings, ...$day);
            }
            $rows = DB::query()->fromRaw('('.implode(' UNION ALL ', $selects).') AS calendar', $bindings)
                ->leftJoin('sales', function (JoinClause $join) {
                    $join->on('sales.created_at', '>=', 'calendar.starts_at')
                        ->on('sales.created_at', '<', 'calendar.ends_at')->where('sales.status', 'completed');
                })->select('calendar.bucket_date')
                ->selectRaw('COALESCE(SUM(sales.total_cents), 0) AS sales_cents, COUNT(sales.id) AS sales_count')
                ->groupBy('calendar.bucket_date')->orderBy('calendar.bucket_date')->get();
            foreach ($rows as $row) {
                $result[$row->bucket_date] = ['sales_cents' => (int) $row->sales_cents, 'sales_count' => (int) $row->sales_count];
            }
        }
        return $result;
    }

    /** Convert each local midnight independently; daylight changes must not become fixed 24-hour windows. */
    private function storageBounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $storageZone = config('app.timezone');
        return [
            $from->startOfDay()->setTimezone($storageZone)->format('Y-m-d H:i:s'),
            $to->addDay()->startOfDay()->setTimezone($storageZone)->format('Y-m-d H:i:s'),
        ];
    }

    private function sales(string $from, string $until): Builder
    {
        return DB::table('sales')->where('sales.status', 'completed')->where('sales.created_at', '>=', $from)->where('sales.created_at', '<', $until);
    }

    private function balance(string $foreignKey): int
    {
        return (int) DB::table('ledger_entries')->whereNotNull($foreignKey)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS balance")
            ->value('balance');
    }
}
