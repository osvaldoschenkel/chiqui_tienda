@php($icon = $name ?? 'grid')
<svg class="icon {{ $class ?? '' }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($icon)
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('bag')<path d="M5 8h14l2 13H3L5 8Z"/><path d="M8 9V6a4 4 0 0 1 8 0v3"/>@break
@case('box')<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v9l9 5 9-5V8M12 13v9M7 5.7l9 5"/>@break
@case('users')<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 3a4 4 0 0 1 0 8M19 14a6 6 0 0 1 3 5v2"/>@break
@case('truck')<path d="M1 4h13v13H1zM14 9h5l4 4v4h-9"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>@break
@case('wallet')<path d="M20 7V4H5a3 3 0 0 0 0 6h16v11H5a3 3 0 0 1-3-3V7M21 13h-5v5h5"/><path d="M17.5 15.5h.01"/>@break
@case('cart')<path d="M2 3h3l3 13h12l2-10H6"/><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>@break
@case('arrow')<path d="M5 12h14m-5-5 5 5-5 5"/>@break
@case('back')<path d="M19 12H5m5-5-5 5 5 5"/>@break
@case('edit')<path d="m16 3 5 5L8 21H3v-5L16 3ZM13 6l5 5"/>@break
@case('trash')<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>@break
@case('check')<path d="m5 12 4 4L19 6"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
@case('logout')<path d="M9 4H4v16h5M9 12h12m-4-4 4 4-4 4"/>@break
@case('print')<path d="M6 8V3h12v5M6 17H3V8h18v9h-3M6 14h12v7H6zM17 11h.01"/>@break
@case('alert')<path d="m12 3 10 18H2L12 3ZM12 9v5M12 17h.01"/>@break
@case('heart')<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>@break
@case('image')<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-7 5 8"/>@break
@default<circle cx="12" cy="12" r="9"/>@endswitch
</svg>
