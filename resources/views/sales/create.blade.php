@extends('layouts.app')
@section('title', 'Nueva venta')
@section('content')
@include('orders.create', ['kind' => 'sales', 'contacts' => $customers])
@endsection
