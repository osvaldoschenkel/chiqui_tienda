<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#7654cf">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Inicio') · Chiqui Tienda</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">Ir al contenido</a>
    <div class="nav-overlay" data-nav-close></div>
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">@include('partials.icon', ['name' => 'bag'])</span><span>chiqui<span class="brand-sub">TIENDA</span></span></a>
        <div class="workspace-pill"><span class="status-dot"></span> Tu espacio de trabajo</div>
        <div class="nav-label">GESTIONÁ TU TIENDA</div>
        <nav class="main-nav">
            @php($links = [['dashboard', 'grid', 'Inicio', 'dashboard'], ['sales.index', 'bag', 'Ventas', 'sales.*'], ['products.index', 'box', 'Productos', 'products.*'], ['customers.index', 'users', 'Clientes', 'customers.*'], ['purchases.index', 'cart', 'Compras', 'purchases.*'], ['suppliers.index', 'truck', 'Proveedores', 'suppliers.*'], ['accounts.index', 'wallet', 'Cuentas corrientes', 'accounts.*']])
            @foreach($links as [$route, $icon, $label, $pattern])
                <a class="nav-item {{ request()->routeIs($pattern) ? 'active' : '' }}" href="{{ route($route) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>@include('partials.icon', ['name' => $icon])<span>{{ $label }}</span>@if(request()->routeIs($pattern))<span class="nav-active-dot"></span>@endif</a>
            @endforeach
        </nav>
        <div class="sidebar-note"><span class="note-spark">✦</span><strong>Todo en su lugar.</strong><p>Más tiempo para hacer crecer tu tienda.</p><a href="{{ route('sales.create') }}">Registrar una venta @include('partials.icon', ['name' => 'arrow'])</a></div>
        <div class="sidebar-user"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 1)) }}</span><span class="user-text"><strong>{{ auth()->user()->name ?? 'Usuario' }}</strong><small>Administración</small></span><form method="POST" action="{{ route('logout') }}">@csrf<button class="icon-button" aria-label="Cerrar sesión" title="Cerrar sesión">@include('partials.icon', ['name' => 'logout'])</button></form></div>
    </aside>
    <div class="app-shell">
        <header class="topbar"><div class="topbar-left"><button type="button" class="icon-button menu-toggle" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false" data-nav-toggle>@include('partials.icon', ['name' => 'menu'])</button><span class="breadcrumb">Tu tienda <span>/</span> <strong>@yield('title', 'Inicio')</strong></span></div><div class="topbar-right"><span class="today-label">{{ now()->locale('es')->translatedFormat('d M Y') }}</span><a class="button button-primary button-small" href="{{ route('sales.create') }}">@include('partials.icon', ['name' => 'plus'])<span>Nueva venta</span></a></div></header>
        <main id="main" class="main-content">
            @if(session('success'))<div class="alert alert-success" role="status">@include('partials.icon', ['name' => 'check'])<span>{{ session('success') }}</span></div>@endif
            @if(session('error'))<div class="alert alert-error" role="alert">@include('partials.icon', ['name' => 'alert'])<span>{{ session('error') }}</span></div>@endif
            @if($errors->any())<div class="alert alert-error" role="alert"><div>@include('partials.icon', ['name' => 'alert'])</div><div><strong>Revisá los datos antes de continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
            @yield('content')
            <footer class="app-footer"><span>Chiqui Tienda</span><span>Hecho para tu día a día.</span></footer>
        </main>
    </div>
    <nav class="mobile-nav" aria-label="Accesos rápidos"><a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">@include('partials.icon', ['name' => 'grid'])<span>Inicio</span></a><a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.*') ? 'active' : '' }}">@include('partials.icon', ['name' => 'bag'])<span>Ventas</span></a><a href="{{ route('sales.create') }}" class="mobile-add" aria-label="Nueva venta">@include('partials.icon', ['name' => 'plus'])</a><a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">@include('partials.icon', ['name' => 'box'])<span>Productos</span></a><button data-nav-toggle type="button" aria-label="Abrir menú">@include('partials.icon', ['name' => 'menu'])<span>Más</span></button></nav>
    @stack('scripts')
</body>
</html>
