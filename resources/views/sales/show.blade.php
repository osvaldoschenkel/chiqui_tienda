@extends('layouts.app')
@section('title', 'Detalle de venta')
@section('content')
@include('orders.show', ['record' => $sale, 'kind' => 'sales'])
@if($sale->shipping_cents)<div class="card section-gap"><div class="card-body">Envío incluido en el total: {{ \App\Services\Money::format($sale->shipping_cents) }}</div></div>@endif
@endsection
