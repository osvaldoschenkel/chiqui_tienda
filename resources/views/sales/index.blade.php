@extends('layouts.app')
@section('title', 'Ventas')
@section('content')
@include('orders.index', ['records' => $sales, 'kind' => 'sales'])
@endsection
