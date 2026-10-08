<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\CommerceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $query = Purchase::with('supplier', 'user');
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('number', 'like', '%'.$search.'%')
                    ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
            });
        }
        if (in_array($request->input('status'), ['completed', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('purchases.index', ['purchases' => $query->latest('id')->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('purchases.create', [
            'products' => Product::where('active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CommerceService $commerce): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'payment_method' => ['required', Rule::in(['cash', 'transfer', 'account'])],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'],
        ]);
        $purchase = $commerce->purchase($data, $request->user()->id);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra registrada. Se actualizó el stock y el costo de los productos.');
    }

    public function show(Purchase $purchase): View
    {
        return view('purchases.show', ['purchase' => $purchase->load('items.product', 'supplier', 'user')]);
    }

    public function cancel(Request $request, Purchase $purchase, CommerceService $commerce): RedirectResponse
    {
        $commerce->cancelPurchase($purchase, $request->user()->id);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra anulada. Se descontó el stock recibido y se compensó la cuenta corriente si correspondía.');
    }
}
