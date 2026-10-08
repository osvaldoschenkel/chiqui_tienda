@extends('layouts.app')
@section('title', 'Detalle de compra')
@section('content')
@include('orders.show', ['record' => $purchase, 'kind' => 'purchases'])
@endsection
