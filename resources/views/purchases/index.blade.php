@extends('layouts.app')
@section('title', 'Compras')
@section('content')
@include('orders.index', ['records' => $purchases, 'kind' => 'purchases'])
@endsection
