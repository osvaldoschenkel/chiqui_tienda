<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $customers = Customer::query();
        $suppliers = Supplier::query();
        if ($search !== '') {
            foreach ([$customers, $suppliers] as $query) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('document', 'like', '%'.$search.'%');
                });
            }
        }

        return view('accounts.index', [
            'customers' => $customers->orderBy('name')->paginate(15, ['*'], 'customers_page')->withQueryString(),
            'suppliers' => $suppliers->orderBy('name')->paginate(15, ['*'], 'suppliers_page')->withQueryString(),
            'customerBalanceCents' => $this->balance('customer_id'),
            'supplierBalanceCents' => $this->balance('supplier_id'),
        ]);
    }

    private function balance(string $foreignKey): int
    {
        return (int) LedgerEntry::whereNotNull($foreignKey)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS balance")
            ->value('balance');
    }
}
