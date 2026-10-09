@if($paginator->hasPages())
<nav class="shop-pagination" aria-label="Paginación"><span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span><div>@if($paginator->onFirstPage())<span class="is-disabled">Anterior</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>@endif<span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>@else<span class="is-disabled">Siguiente</span>@endif</div></nav>
@endif
