@php($storeBrand = $storeBrand ?? app('store.brand'))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#087df0">
    <title>@yield('title', 'Tienda') · {{ $storeBrand['name'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/shop.css') }}">
    @stack('head')
</head>
<body class="shop-body">
    <a class="shop-skip" href="#shop-main">Ir al contenido</a>
    <header class="shop-header">
        <div class="shop-welcome"><div class="shop-container"><span>Bienvenido a {{ $storeBrand['name'] }} · {{ $storeBrand['tagline'] }}</span><a href="{{ route('shop.orders.index') }}">Seguí tus compras @include('partials.icon', ['name' => 'arrow'])</a></div></div>
        <div class="shop-container shop-header-main">
            <a class="shop-brand" href="{{ route('shop.index') }}" aria-label="{{ $storeBrand['name'] }}, inicio">
                @if($storeBrand['logo_url'])<span class="shop-brand-logo"><img src="{{ $storeBrand['logo_url'] }}" alt="" width="48" height="48"></span>@else<span class="shop-brand-mark" aria-hidden="true">{{ $storeBrand['initials'] }}</span>@endif
                <span><strong>{{ $storeBrand['name'] }}</strong><small>COMPUTACIÓN & TECNOLOGÍA</small></span>
            </a>
            <form method="GET" action="{{ route('shop.index') }}" class="shop-search" role="search"><label class="shop-sr-only" for="shop-search">Buscar productos</label><input id="shop-search" name="q" type="search" value="{{ request('q') }}" placeholder="¿Qué estás buscando?"><button type="submit" aria-label="Buscar">@include('partials.icon', ['name' => 'search'])</button></form>
            <div class="shop-header-actions">
                <a class="shop-account-link" href="{{ auth()->check() ? route('shop.orders.index') : route('login') }}">@include('partials.icon', ['name' => 'users'])<span>{{ auth()->check() ? 'Hola, '.\Illuminate\Support\Str::before(auth()->user()->name, ' ') : 'Ingresar' }}<small>Mi cuenta y mis compras</small></span></a>
                <a class="shop-header-cart" href="{{ route('shop.cart.index') }}" aria-label="Carrito, {{ $cartCount ?? 0 }} unidades">@include('partials.icon', ['name' => 'bag'])<b>{{ $cartCount ?? 0 }}</b></a>
            </div>
        </div>
        <nav class="shop-nav" aria-label="Navegación de la tienda"><div class="shop-container"><a class="shop-category-link" href="{{ route('shop.index') }}#categorias">@include('partials.icon', ['name' => 'menu']) Categorías @include('partials.icon', ['name' => 'chevron'])</a><a href="{{ route('shop.index') }}#productos" @if(request()->routeIs('shop.index')) aria-current="page" @endif>Todos los productos</a><a href="{{ route('shop.orders.index') }}" @if(request()->routeIs('shop.orders.*')) aria-current="page" @endif>Mis compras</a>@auth @if(auth()->user()->isAdmin())<a class="shop-admin-link" href="{{ route('dashboard') }}">@include('partials.icon', ['name' => 'grid']) Administración</a>@endif @endauth<span>Tecnología para todos los días</span></div></nav>
    </header>
    <main id="shop-main">
        @if(session('success'))<div class="shop-container shop-notice shop-notice-success" role="status">@include('partials.icon', ['name' => 'check'])<span>{{ session('success') }}</span></div>@endif
        @if(session('error'))<div class="shop-container shop-notice shop-notice-error" role="alert">@include('partials.icon', ['name' => 'alert'])<span>{{ session('error') }}</span></div>@endif
        @if($errors->any())<div class="shop-container shop-notice shop-notice-error" role="alert"><div>@include('partials.icon', ['name' => 'alert'])</div><div><strong>Revisá los datos antes de continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
    </main>
    <section class="shop-help-strip"><div class="shop-container"><div>@include('partials.icon', ['name' => 'users'])<span><strong>Tu próximo pedido, siempre a mano.</strong><p>Consultá tus compras y seguí cada actualización desde tu cuenta.</p></span></div><a class="shop-button shop-button-light" href="{{ route('shop.orders.index') }}">Ver mis compras @include('partials.icon', ['name' => 'arrow'])</a></div></section>
    <footer class="shop-footer"><div class="shop-container shop-footer-main"><a class="shop-brand" href="{{ route('shop.index') }}"><span class="shop-brand-mark">{{ $storeBrand['initials'] }}</span><span><strong>{{ $storeBrand['name'] }}</strong><small>{{ $storeBrand['tagline'] }}</small></span></a><div><strong>La tienda</strong><a href="{{ route('shop.index') }}">Productos</a><a href="{{ route('shop.index') }}#categorias">Categorías</a></div><div><strong>Tu cuenta</strong><a href="{{ route('shop.orders.index') }}">Mis compras</a><a href="{{ route('shop.cart.index') }}">Mi carrito</a>@auth<form method="POST" action="{{ route('logout') }}">@csrf<button class="shop-footer-logout" type="submit">Cerrar sesión</button></form>@endauth</div><div><strong>Entrega y pago</strong><span>Retiro a coordinar</span><span>Pago a coordinar con la tienda</span></div></div><div class="shop-container shop-footer-bottom"><span>© {{ now()->year }} {{ $storeBrand['name'] }}</span><span>Los precios se expresan en pesos argentinos.</span></div></footer>
    @stack('scripts')
</body>
</html>
