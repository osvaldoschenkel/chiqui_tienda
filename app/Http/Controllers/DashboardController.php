<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = CarbonImmutable::now('America/Argentina/Buenos_Aires');
        $from = $today->startOfDay()->setTimezone(config('app.timezone'));
        $until = $today->addDay()->startOfDay()->setTimezone(config('app.timezone'));
        $salesToday = Sale::where('status', 'completed')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $purchasesToday = Purchase::where('status', 'completed')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $lowStock = Product::where('active', true)->whereColumn('stock', '<=', 'min_stock');

        return view('dashboard', [
            'today' => $today,
            'todaySalesCents' => (int) (clone $salesToday)->sum('total_cents'),
            'todayPurchasesCents' => (int) $purchasesToday->sum('total_cents'),
            'salesCount' => $salesToday->count(),
            'lowStockCount' => (clone $lowStock)->count(),
            'lowStockProducts' => $lowStock->orderBy('stock')->orderBy('name')->limit(8)->get(),
            'customerBalanceCents' => $this->balance('customer_id'),
            'supplierBalanceCents' => $this->balance('supplier_id'),
            'recentSales' => Sale::with('customer')->latest('id')->limit(8)->get(),
        ]);
    }

    private function balance(string $foreignKey): int
    {
        return (int) LedgerEntry::whereNotNull($foreignKey)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS balance")
            ->value('balance');
    }
}
