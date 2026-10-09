@php($statusLabels = \App\Models\CustomerOrder::STATUSES)
<span class="shop-status shop-status-{{ $status }}"><i></i>{{ $statusLabels[$status] ?? 'Pendiente' }}</span>
