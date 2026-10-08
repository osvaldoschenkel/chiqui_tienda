@php
    $purchase = $kind === 'purchases';
    $contactField = $purchase ? 'supplier_id' : 'customer_id';
    $catalogData = $products->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'barcode' => $product->barcode, 'stock' => $product->stock, 'price' => $product->price_cents, 'cost' => $product->cost_cents])->values();
@endphp
<div class="page-heading"><div><div class="eyebrow">{{ $purchase ? 'INGRESO DE MERCADERÍA' : 'LISTO PARA VENDER' }}</div><h1>{{ $purchase ? 'Nueva compra' : 'Nueva venta' }}</h1><p>{{ $purchase ? 'Seleccioná productos y cargá el costo de cada unidad.' : 'Agregá productos y confirmá la forma de pago.' }}</p></div><a class="button button-secondary" href="{{ route($kind.'.index') }}">@include('partials.icon', ['name' => 'back'])Volver</a></div>
<noscript><div class="alert alert-error" role="alert">Activá JavaScript en tu navegador para agregar productos y registrar esta operación.</div></noscript>
<form method="POST" action="{{ route($kind.'.store') }}" data-order-form data-order-kind="{{ $kind }}">
    @csrf<input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
    <div class="pos-layout">
        <section aria-label="Catálogo de productos">
            <div class="catalog-header"><h2>Elegí tus productos</h2><div class="search-field">@include('partials.icon', ['name' => 'search'])<label class="sr-only" for="catalog-search">Buscar por nombre, SKU o código de barras</label><input id="catalog-search" class="input" type="search" placeholder="Buscar producto o código…" autocomplete="off" data-catalog-search></div></div>
            @if($products->isEmpty())
                <div class="card">@include('partials.empty', ['icon' => 'box', 'heading' => $purchase ? 'Agregá productos para comprar' : 'No hay productos disponibles', 'description' => $purchase ? 'Creá los productos de tu catálogo antes de recibir mercadería.' : 'Necesitás productos activos y con stock para registrar una venta.', 'url' => route('products.create'), 'action' => 'Agregar producto'])</div>
            @else
                <div class="catalog-grid" data-catalog-grid>
                @foreach($products as $product)
                    <button class="catalog-product" type="button" data-add-product="{{ $product->id }}" data-product-search="{{ mb_strtolower($product->name.' '.$product->sku.' '.$product->barcode) }}" aria-label="Agregar {{ $product->name }}">
                        <span class="catalog-art">@if($product->image_path)<img src="{{ asset('storage/'.$product->image_path) }}" alt="" loading="lazy">@else @include('partials.icon', ['name' => 'box']) @endif</span><span class="catalog-details"><span class="row-title">{{ $product->name }}</span><span class="row-sub">{{ $product->sku }} · {{ $product->stock }} u.</span><span class="catalog-price"><span class="money">{{ \App\Services\Money::format($purchase ? $product->cost_cents : $product->price_cents) }}</span><span class="catalog-plus">@include('partials.icon', ['name' => 'plus'])</span></span></span>
                    </button>
                @endforeach
                    <p class="pos-no-results" data-catalog-empty hidden>No encontramos ese producto. Probá otro nombre o código.</p>
                </div><p class="catalog-count" data-catalog-count>{{ $products->count() }} productos disponibles</p>
            @endif
        </section>
        <section class="card checkout" aria-label="Detalle de la operación"><div class="card-header"><h2>{{ $purchase ? 'Tu compra' : 'Tu venta' }}</h2><span data-cart-count>0 productos</span></div><div class="checkout-body">
            <div data-cart-empty class="cart-empty">@include('partials.icon', ['name' => $purchase ? 'cart' : 'bag'])Agregá productos para empezar.</div><div data-cart-items></div><div data-cart-hidden></div>
            <p class="cart-limit-message" data-cart-message aria-live="polite"></p>
            <div class="checkout-fields"><div class="field"><label for="order-contact">{{ $purchase ? 'Proveedor' : 'Cliente' }} @if($purchase)<span class="required">*</span>@endif</label><select id="order-contact" name="{{ $contactField }}" data-order-contact @required($purchase)><option value="">{{ $purchase ? 'Seleccioná un proveedor' : 'Consumidor final' }}</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}" @selected((string)old($contactField) === (string)$contact->id)>{{ $contact->name }}</option>@endforeach</select><p class="hint">{{ $purchase ? 'El proveedor que te entrega la mercadería.' : 'Para cuenta corriente, seleccioná un cliente.' }}</p></div>
            <div class="field"><label for="payment-method">Forma de pago <span class="required">*</span></label><select id="payment-method" name="payment_method" required data-payment-method><option value="cash" @selected(old('payment_method', 'cash') === 'cash')>Efectivo</option>@unless($purchase)<option value="card" @selected(old('payment_method') === 'card')>Tarjeta</option>@endunless<option value="transfer" @selected(old('payment_method') === 'transfer')>Transferencia</option><option value="account" @selected(old('payment_method') === 'account')>Cuenta corriente</option></select></div></div>
            <div class="checkout-total"><span>Total</span><span class="money" data-cart-total>$ 0,00</span></div><button class="button button-primary button-wide" type="submit" data-order-submit disabled>@include('partials.icon', ['name' => 'check']){{ $purchase ? 'Registrar compra' : 'Confirmar venta' }}</button><p class="checkout-message" data-payment-hint>{{ $purchase ? 'Al confirmar, la mercadería se suma al stock.' : 'Registrá el cobro una vez recibido el pago.' }}</p>
        </div></section>
    </div>
</form>
<script type="application/json" id="order-catalog">{!! json_encode(['products' => $catalogData, 'items' => old('items', [])], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
