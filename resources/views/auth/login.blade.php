@php
    $storeBrand = $storeBrand ?? ['name' => 'Chiqui Tienda', 'tagline' => 'Tu tienda, más simple.', 'color' => '#c45130', 'logo_url' => null, 'initials' => 'CT'];
    $brandColor = in_array($storeBrand['color'], ['#c45130', '#7054c8', '#1d7669', '#245ca6'], true) ? $storeBrand['color'] : '#c45130';
@endphp
<!DOCTYPE html>
<html lang="es" style="--brand: {{ $brandColor }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="{{ $brandColor }}"><title>Ingresar · {{ $storeBrand['name'] }}</title><link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}"><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="login-body">
    <section class="login-art">
        <a class="brand" href="{{ route('login') }}">@if($storeBrand['logo_url'])<span class="brand-mark brand-mark-logo"><img src="{{ $storeBrand['logo_url'] }}" alt="" width="44" height="44"></span>@else<span class="brand-mark" aria-hidden="true">{{ $storeBrand['initials'] }}</span>@endif<span class="brand-text"><strong>{{ $storeBrand['name'] }}</strong><span class="brand-sub">TU NEGOCIO, EN ORDEN</span></span></a>
        <div class="login-art-center"><div class="eyebrow">HECHO PARA TU DÍA A DÍA</div><h1>Más tienda.<br>Menos vueltas.</h1><p>{{ $storeBrand['tagline'] }}</p>
            <div class="login-store-visual" aria-hidden="true"><div class="login-visual-orbit"></div><div class="login-visual-bag">@include('partials.icon', ['name' => 'brand'])</div><div class="login-visual-label label-sales">@include('partials.icon', ['name' => 'bag'])<span>Ventas en orden</span><span class="visual-check">✓</span></div><div class="login-visual-label label-stock">@include('partials.icon', ['name' => 'box'])<span>Stock al día</span><span class="visual-check">✓</span></div><div class="login-visual-label label-accounts">@include('partials.icon', ['name' => 'wallet'])<span>Cuentas claras</span><span class="visual-check">✓</span></div><span class="login-spark spark-one">✦</span><span class="login-spark spark-two">✦</span></div>
        </div>
        <div class="login-art-footer">Un lugar para todo lo que hace crecer tu negocio.</div>
    </section>
    <main class="login-main"><div class="login-form">
        <a class="brand login-mobile-brand" href="{{ route('login') }}">@if($storeBrand['logo_url'])<span class="brand-mark brand-mark-logo"><img src="{{ $storeBrand['logo_url'] }}" alt="" width="44" height="44"></span>@else<span class="brand-mark" aria-hidden="true">{{ $storeBrand['initials'] }}</span>@endif<span class="brand-text"><strong>{{ $storeBrand['name'] }}</strong><span class="brand-sub">TU NEGOCIO, EN ORDEN</span></span></a>
        <div class="eyebrow">QUÉ BUENO VERTE</div><h1>Bienvenido de nuevo.</h1><p>Ingresá a {{ $storeBrand['name'] }} y seguí con tu día.</p>
        @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('login.store') }}">@csrf<div class="field"><label for="email">Correo electrónico</label><input id="email" class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="username" placeholder="vos@tutienda.com" required autofocus></div><div class="field"><label for="password">Contraseña</label><input id="password" class="input" type="password" name="password" autocomplete="current-password" placeholder="Tu contraseña" required></div><div class="login-options"><label><input name="remember" value="1" type="checkbox" {{ old('remember') ? 'checked' : '' }}> Mantener mi sesión</label><span class="badge badge-gray">Acceso privado</span></div><button class="button button-primary button-wide" type="submit">Ingresar a mi tienda @include('partials.icon', ['name' => 'arrow'])</button></form>
        <div class="login-footnote">¿Necesitás acceso? Contactá a quien administra la tienda.</div><div class="login-signoff"><span>{{ $storeBrand['name'] }}</span><span>{{ $storeBrand['tagline'] }}</span></div>
    </div></main>
</body></html>
