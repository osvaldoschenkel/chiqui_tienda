@extends('layouts.app')
@section('title', 'Detalle de venta')
@section('content')
@include('orders.show', ['record' => $sale, 'kind' => 'sales'])
@endsection
