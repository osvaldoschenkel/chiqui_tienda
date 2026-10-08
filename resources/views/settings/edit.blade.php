@extends('layouts.app')
@section('title', 'Mi tienda')
@section('content')
@php
    $oldName = old('name', $storeBrand['name']);
    $oldTagline = old('tagline', $storeBrand['tagline']);
    $previewName = is_string($oldName) ? $oldName : '';
    $previewTagline = is_string($oldTagline) ? $oldTagline : '';
    $oldColor = old('color', $storeBrand['color']);
    $previewColor = is_string($oldColor) && array_key_exists($oldColor, $brandColors) ? $oldColor : $storeBrand['color'];
    $hasLogo = $storeBrand['logo_url'] !== null && ! old('remove_logo');
@endphp
<div class="page-heading"><div><div class="eyebrow">TU IDENTIDAD</div><h1>Mi tienda</h1><p>Dale tu nombre, tus colores y tu personalidad a cada día de trabajo.</p></div><a class="button button-secondary" href="{{ route('dashboard') }}">@include('partials.icon', ['name' => 'back'])Volver al inicio</a></div>
<form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" data-store-settings data-submit-once>
@csrf @method('PUT')
<div class="settings-grid">
    <div class="form-main">
        <section class="card">
            <div class="card-header"><div><h2>La identidad de tu negocio</h2><p>La verás al ingresar, en tu menú y en tus comprobantes internos.</p></div><span class="stat-icon">@include('partials.icon', ['name' => 'bag'])</span></div>
            <div class="card-body">
                <div class="field"><label for="store-name">Nombre de tu tienda <span class="required">*</span></label><input class="input" id="store-name" name="name" value="{{ $previewName }}" maxlength="80" placeholder="Ej. Almacén Las Flores" required data-brand-name>@error('name')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field section-gap"><label for="store-tagline">Una frase para tu tienda</label><input class="input" id="store-tagline" name="tagline" value="{{ $previewTagline }}" maxlength="160" placeholder="Ej. Todo lo que necesitás, cerca tuyo." data-brand-tagline><p class="hint">Es opcional. Dejalo vacío si preferís usar sólo el nombre.</p>@error('tagline')<span class="field-error">{{ $message }}</span>@enderror</div>
                <fieldset class="field section-gap"><legend>Tu color principal <span class="required">*</span></legend><div class="brand-color-options">
                    @foreach($brandColors as $color => $label)
                    <label class="brand-color-option"><input type="radio" name="color" value="{{ $color }}" @checked($previewColor === $color) required data-brand-color><span class="brand-color-swatch" style="background-color: {{ $color }}" aria-hidden="true"></span><span>{{ $label }}</span></label>
                    @endforeach
                </div><p class="hint">Elegí una paleta pensada para que tu tienda se lea bien en cualquier pantalla.</p>@error('color')<span class="field-error">{{ $message }}</span>@enderror</fieldset>
            </div>
        </section>
        <section class="card section-gap">
            <div class="card-header"><div><h2>El logo de tu tienda</h2><p>Si todavía no tenés uno, usaremos las iniciales de tu nombre.</p></div></div>
            <div class="card-body"><div class="logo-upload">@include('partials.icon', ['name' => 'image'])<label for="store-logo">Elegí un logo</label><input id="store-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-brand-logo-input><p class="hint">JPG, PNG o WebP · hasta 2 MB. Un logo cuadrado se adapta mejor.</p>@if($storeBrand['logo_url'])<p class="hint">Tu logo actual se conservará si no elegís uno nuevo.</p>@endif</div>@error('logo')<span class="field-error">{{ $message }}</span>@enderror
                @if($storeBrand['logo_url'])<div class="toggle-field section-gap"><input type="hidden" name="remove_logo" value="0"><input id="remove-logo" type="checkbox" name="remove_logo" value="1" @checked(old('remove_logo')) data-brand-remove-logo><label for="remove-logo">Quitar el logo actual<small>Volveremos a mostrar las iniciales de tu tienda.</small></label></div>@endif
            </div>
        </section>
        <div class="form-actions"><a class="button button-secondary" href="{{ route('dashboard') }}">Cancelar</a><button class="button button-primary" type="submit">@include('partials.icon', ['name' => 'check'])Guardar mi tienda</button></div>
    </div>
    <aside>
        <section class="card brand-preview" style="--preview-color: {{ $previewColor }}" data-brand-preview>
            <div class="card-header"><div><h2>Así se verá tu tienda</h2><p>Una vista previa de tu nueva identidad.</p></div></div>
            <div class="card-body brand-preview-body">
                <div class="brand-preview-mark"><img @if($storeBrand['logo_url']) src="{{ $storeBrand['logo_url'] }}" @endif alt="Logo de tu tienda" data-brand-preview-logo data-current-logo="{{ $storeBrand['logo_url'] ?? '' }}" @if(! $hasLogo) hidden @endif><span data-brand-preview-initials @if($hasLogo) hidden @endif>{{ \App\Models\StoreSetting::initials($previewName ?: 'Tu tienda') }}</span></div>
                <h3 class="brand-preview-name" data-brand-preview-name>{{ $previewName ?: 'Tu tienda' }}</h3>
                <p class="brand-preview-tagline" data-brand-preview-tagline @if(! $previewTagline) hidden @endif>{{ $previewTagline }}</p>
                <span class="badge">Tu espacio de trabajo</span>
            </div>
        </section>
        <div class="info-panel section-gap">@include('partials.icon', ['name' => 'heart'])<h3>Un espacio que habla de vos</h3><p>El nombre, el logo y el color se aplican a tu tienda cuando guardás los cambios.</p></div>
    </aside>
</div>
</form>
@endsection
@push('scripts')
<script src="{{ asset('js/settings.js') }}" defer></script>
@endpush
