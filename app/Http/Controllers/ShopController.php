<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShopController extends Controller
{
    private const CART_KEY = 'shop.cart';
    private const MAX_QUANTITY = 100;
    private const MAX_LINES = 30;

    public function index(Request $request): View
    {
        $query = Product::query()->where('active', true);
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'));
        }
        if ($request->filled('category')) $query->where('category', $request->input('category'));

        return view('shop.index', array_merge($this->cartState($request), [
            'products' => $query->orderBy('name')->paginate(12)->withQueryString(),
            'categories' => Product::where('active', true)->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category'),
        ]));
    }

    public function cart(Request $request): View
    {
        return view('shop.cart', array_merge($this->cartState($request), ['requestId' => (string) Str::uuid()]));
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY]]);
        $cart = $this->sessionCart($request);
        $quantity = ($cart[$product->id] ?? 0) + (int) ($data['quantity'] ?? 1);
        $this->assertAvailable($product, $quantity);
        $cart[$product->id] = $quantity;
        if (count($cart) > self::MAX_LINES) throw ValidationException::withMessages(['cart' => 'El carrito admite hasta 30 productos diferentes.']);
        $request->session()->put(self::CART_KEY, $cart);

        return redirect()->back()->with('success', 'Producto agregado al carrito.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY]]);
        $cart = $this->sessionCart($request);
        abort_unless(isset($cart[$product->id]), 404);
        $quantity = (int) $data['quantity'];
        $this->assertAvailable($product, $quantity);
        $cart[$product->id] = $quantity;
        $request->session()->put(self::CART_KEY, $cart);

        return redirect()->route('shop.cart.index')->with('success', 'Cantidad actualizada.');
    }

    public function remove(Request $request, string $product): RedirectResponse
    {
        $cart = $this->sessionCart($request);
        unset($cart[(int) $product]);
        $request->session()->put(self::CART_KEY, $cart);

        return redirect()->route('shop.cart.index')->with('success', 'Producto quitado del carrito.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'delivery_method' => ['required', Rule::in(['pickup'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'array:name,phone,street,number,floor,city,state,postcode'],
            'address.name' => ['nullable', 'string', 'max:150'],
            'address.phone' => ['nullable', 'string', 'max:50'],
            'address.street' => ['nullable', 'string', 'max:150'],
            'address.number' => ['nullable', 'string', 'max:20'],
            'address.floor' => ['nullable', 'string', 'max:80'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.state' => ['nullable', 'string', 'max:100'],
            'address.postcode' => ['nullable', 'string', 'max:20'],
        ]);
        $data['notes'] = trim($data['notes'] ?? '') ?: null;
        $data['address'] = array_filter($data['address'] ?? [], fn ($value) => $value !== null && $value !== '');
        ksort($data['address']);
        $data['address'] = $data['address'] ?: null;
        $userId = (int) $request->user()->id;
        $cart = $this->sessionCart($request);
        $existing = CustomerOrder::where('user_id', $userId)->where('request_id', $data['request_id'])->first();

        // A retry after the successful redirect has an empty session cart.
        if ($existing && $cart === []) {
            foreach ($existing->items as $item) $cart[(int) $item['product_id']] = (int) $item['quantity'];
        }
        if ($cart === []) throw ValidationException::withMessages(['cart' => 'Agregá al menos un producto al carrito.']);
        $requestHash = $this->checkoutHash($cart, $data);
        if ($existing) {
            $this->assertSameRequest($existing, $requestHash);
            $request->session()->forget(self::CART_KEY);

            return redirect()->route('shop.orders.show', $existing)->with('success', 'Este pedido ya estaba registrado. No se duplicó la reserva.');
        }

        try {
            $order = DB::transaction(function () use ($request, $userId, $data, $cart, $requestHash) {
                // Serialize submissions by the same account as well as product inventory.
                $request->user()->newQuery()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $existing = CustomerOrder::where('user_id', $userId)->where('request_id', $data['request_id'])->lockForUpdate()->first();
                if ($existing) { $this->assertSameRequest($existing, $requestHash); return $existing; }
                $products = Product::whereIn('id', array_keys($cart))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $items = []; $total = 0;
                foreach ($cart as $id => $quantity) {
                    $product = $products->get($id);
                    if (! $product) throw ValidationException::withMessages(['cart' => 'Un producto del carrito ya no está disponible. Quitalo para continuar.']);
                    $this->assertAvailable($product, $quantity);
                    if ($product->price_cents < 0) throw ValidationException::withMessages(['cart' => 'Un producto necesita un precio válido.']);
                    $items[] = [
                        'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                        'unit_price_cents' => $product->price_cents, 'quantity' => $quantity, 'image_path' => $product->image_path,
                    ];
                    $total += $product->price_cents * $quantity;
                }
                foreach ($cart as $id => $quantity) {
                    $changed = Product::whereKey($id)->where('active', true)->where('stock', '>=', $quantity)->decrement('stock', $quantity);
                    if ($changed !== 1) throw ValidationException::withMessages(['cart' => 'El stock cambió mientras confirmabas. Revisá el carrito.']);
                }
                $order = CustomerOrder::create([
                    'user_id' => $userId, 'number' => 'CHI-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                    'request_id' => $data['request_id'], 'request_hash' => $requestHash, 'items' => $items,
                    'total_cents' => $total, 'status' => 'pending', 'payment_status' => 'pending',
                    'delivery_method' => 'pickup', 'address' => $data['address'], 'notes' => $data['notes'],
                ]);
                foreach ($items as $item) {
                    StockMovement::create([
                        'product_id' => $item['product_id'], 'delta' => -$item['quantity'],
                        'reference_type' => 'customer_order', 'reference_id' => $order->id,
                        'note' => 'Reserva de stock del pedido '.$order->number.'. Pago pendiente, sin venta POS.',
                        'user_id' => $userId,
                    ]);
                }

                return $order;
            }, 3);
        } catch (QueryException $exception) {
            $order = CustomerOrder::where('user_id', $userId)->where('request_id', $data['request_id'])->first();
            if (! $order) throw $exception;
            $this->assertSameRequest($order, $requestHash);
        }
        $request->session()->forget(self::CART_KEY);

        return redirect()->route('shop.orders.show', $order)->with('success', 'Pedido recibido y stock reservado. El pago sigue pendiente; coordiná el retiro con la tienda. No se realizó ningún cobro automático.');
    }

    public function orders(Request $request): View
    {
        return view('shop.orders.index', array_merge($this->cartState($request), [
            'orders' => CustomerOrder::where('user_id', $request->user()->id)->latest('id')->paginate(12),
            'statuses' => CustomerOrder::STATUSES,
        ]));
    }

    public function show(Request $request, string $order): View
    {
        $ownOrder = CustomerOrder::where('user_id', $request->user()->id)->findOrFail($order);

        return view('shop.orders.show', array_merge($this->cartState($request), ['order' => $ownOrder, 'statuses' => CustomerOrder::STATUSES]));
    }

    private function sessionCart(Request $request): array
    {
        $raw = $request->session()->get(self::CART_KEY, []);
        if (! is_array($raw) || count($raw) > self::MAX_LINES) throw ValidationException::withMessages(['cart' => 'El carrito no es válido.']);
        $cart = [];
        foreach ($raw as $id => $quantity) {
            if (! preg_match('/\A[1-9]\d{0,17}\z/', (string) $id) || ! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY || isset($cart[(int) $id])) {
                throw ValidationException::withMessages(['cart' => 'Revisá los productos y cantidades del carrito.']);
            }
            $cart[(int) $id] = $quantity;
        }
        ksort($cart, SORT_NUMERIC);

        return $cart;
    }

    private function cartState(Request $request): array
    {
        $quantities = $this->sessionCart($request);
        $products = Product::whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $cart = []; $total = 0;
        foreach ($quantities as $id => $quantity) {
            $product = $products->get($id);
            $subtotal = ($product?->price_cents ?? 0) * $quantity;
            $cart[$id] = [
                'product_id' => $id, 'product' => $product, 'name' => $product?->name ?? 'Producto no disponible',
                'image_path' => $product?->image_path, 'quantity' => $quantity,
                'unit_price_cents' => $product?->price_cents ?? 0, 'subtotal_cents' => $subtotal,
                'available' => $product && $product->active && $product->stock >= $quantity,
            ];
            $total += $subtotal;
        }

        return ['cart' => $cart, 'cartCount' => array_sum($quantities), 'cartTotalCents' => $total];
    }

    private function assertAvailable(Product $product, int $quantity): void
    {
        if (! $product->active) throw ValidationException::withMessages(['cart' => 'Ese producto ya no está disponible.']);
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) throw ValidationException::withMessages(['quantity' => 'Podés pedir entre 1 y 100 unidades de cada producto.']);
        if ($quantity > $product->stock) throw ValidationException::withMessages(['cart' => 'Quedan '.$product->stock.' unidades de '.$product->name.'.']);
    }

    private function checkoutHash(array $cart, array $data): string
    {
        ksort($cart, SORT_NUMERIC);

        return hash('sha256', json_encode([$cart, $data['delivery_method'], $data['notes'], $data['address']], JSON_THROW_ON_ERROR));
    }

    private function assertSameRequest(CustomerOrder $order, string $hash): void
    {
        if (! hash_equals($order->request_hash, $hash)) throw ValidationException::withMessages(['request_id' => 'Ese formulario ya se usó para otro pedido. Volvé a abrir el carrito para confirmar los nuevos datos.']);
    }
}
