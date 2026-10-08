@extends('layouts.app')
@section('title', 'Nueva compra')
@section('content')
@include('orders.create', ['kind' => 'purchases', 'contacts' => $suppliers])
@endsection
