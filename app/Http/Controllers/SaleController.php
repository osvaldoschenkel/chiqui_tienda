<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\CommerceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function index(Request $request): View
    {
        $query = Sale::with('customer', 'user');
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
            });
        }
        if (in_array($request->input('status'), ['completed', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('sales.index', ['sales' => $query->latest('id')->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('sales.create', [
            'products' => Product::where('active', true)->where('stock', '>', 0)->orderBy('name')->get(),
            'customers' => Customer::where('active', true)->orderBy('name')->get(),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CommerceService $commerce): RedirectResponse
    {
        // Prices and totals are always calculated again on the server from locked products.
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'account'])],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $sale = $commerce->sell($data, $request->user()->id);

        return redirect()->route('sales.show', $sale)->with('success', 'Venta registrada correctamente.');
    }

    public function show(Sale $sale): View
    {
        return view('sales.show', ['sale' => $sale->load('items.product', 'customer', 'user')]);
    }

    public function cancel(Request $request, Sale $sale, CommerceService $commerce): RedirectResponse
    {
        $commerce->cancelSale($sale, $request->user()->id);

        return redirect()->route('sales.show', $sale)->with('success', 'Venta anulada. Se repuso el stock y se compensó la cuenta corriente si correspondía.');
    }
}
