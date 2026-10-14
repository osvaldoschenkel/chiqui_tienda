@extends('layouts.app')
@section('title','Marcas')
@section('content')
<div class="page-heading"><h1>Marcas</h1><a class="button button-primary" href="{{ route('brands.create') }}">Nueva marca</a></div>
<section class="card"><div class="card-body"><form><input class="input" name="q" value="{{ request('q') }}" placeholder="Buscar marca"><button class="button">Buscar</button></form><table><thead><tr><th>Marca</th><th>Productos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>@foreach($brands as $brand)<tr><td>{{ $brand->name }}</td><td>{{ $brand->products_count }}</td><td>{{ $brand->active?'Activa':'Inactiva' }}</td><td><a href="{{ route('brands.edit',$brand) }}">Editar</a> @if(!$brand->products_count)<form method="POST" action="{{ route('brands.destroy',$brand) }}">@csrf @method('DELETE')<button class="button button-secondary">Eliminar marca sin productos</button></form>@endif</td></tr>@endforeach</tbody></table>{{ $brands->withQueryString()->links() }}</div></section>
@endsection
