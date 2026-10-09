'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, ArrowUpRight, CheckCircle2, ChevronDown, CreditCard, LockKeyhole, Package, RefreshCw, ShoppingBag, Truck, UserRound } from 'lucide-react';
import StoreHeader from './StoreHeader';
import { ars, type Order, type StoreData } from '../lib/domain';

type Filter = 'all' | 'progress' | 'delivered' | 'cancelled';
async function api<T>(url: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(url, { signal, credentials: 'same-origin', cache: 'no-store' });
  const body: unknown = await response.json().catch(() => null);
  const problem = body && typeof body === 'object' ? body as { error?: unknown; message?: unknown } : null;
  if (!response.ok) throw new Error(typeof problem?.error === 'string' ? problem.error : typeof problem?.message === 'string' ? problem.message : `No pudimos completar la solicitud (${response.status}).`);
  if (!body) throw new Error('El servidor devolvió una respuesta inesperada.');
  return body as T;
}
function message(error: unknown) { return error instanceof Error ? error.message : 'Ocurrió un error. Intentá nuevamente.'; }
function safeHttps(value: string | null | undefined) { try { const url = new URL(value || ''); return url.protocol === 'https:' ? url.href : null; } catch { return null; } }
function orderDate(value: string) { const result = new Date(value); return Number.isNaN(result.getTime()) ? value : result.toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' }); }
function paymentLabel(value: string) { return ({ approved: 'Aprobado', pending: 'Pendiente', in_process: 'En proceso', rejected: 'Rechazado', cancelled: 'Cancelado', refunded: 'Reembolsado', authorized: 'Autorizado', charged_back: 'Contracargo' } as Record<string, string>)[value.toLowerCase()] || value; }
function paymentComplete(value: string) { return ['approved', 'aprobado', 'pagado', 'acreditado'].includes(value.toLowerCase()); }
function canPay(order: Order) { return order.status !== 'Cancelado' && !paymentComplete(order.paymentStatus) && !['cancelled', 'cancelado', 'refunded', 'reembolsado', 'charged_back'].includes(order.paymentStatus.toLowerCase()); }
function stateClass(value: string) { return value === 'Entregado' ? 'delivered' : value === 'Cancelado' ? 'cancelled' : value === 'Enviado' ? 'shipped' : 'pending'; }

export default function CustomerPanel() {
  const [store, setStore] = useState<StoreData | null>(null);
  const [loading, setLoading] = useState(true);
  const [orders, setOrders] = useState<Order[] | null>(null);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState<Filter>('all');

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      try {
        const data = await api<StoreData>('/api/store', controller.signal); setStore(data);
        if (data.session) { setOrdersLoading(true); const result = await api<{ orders: Order[] }>('/api/orders', controller.signal); setOrders(result.orders); }
      } catch (failure) { if (!controller.signal.aborted) setError(message(failure)); }
      finally { if (!controller.signal.aborted) { setLoading(false); setOrdersLoading(false); } }
    }
    void load(); return () => controller.abort();
  }, []);

  async function retry() {
    setError(''); setOrdersLoading(true);
    try {
      const data = await api<StoreData>('/api/store'); setStore(data);
      if (data.session) { const result = await api<{ orders: Order[] }>('/api/orders'); setOrders(result.orders); } else setOrders(null);
    } catch (failure) { setOrders(null); setError(message(failure)); }
    finally { setLoading(false); setOrdersLoading(false); }
  }

  const list = orders || [];
  const inProgress = list.filter(order => !['Entregado', 'Cancelado'].includes(order.status)).length;
  const delivered = list.filter(order => order.status === 'Entregado').length;
  const cancelled = list.filter(order => order.status === 'Cancelado').length;
  const visible = list.filter(order => filter === 'all' || filter === 'progress' && !['Entregado', 'Cancelado'].includes(order.status) || filter === 'delivered' && order.status === 'Entregado' || filter === 'cancelled' && order.status === 'Cancelado');
  const filters: { id: Filter; title: string; count: number }[] = [{ id: 'all', title: 'Todas', count: list.length }, { id: 'progress', title: 'En curso', count: inProgress }, { id: 'delivered', title: 'Entregadas', count: delivered }, { id: 'cancelled', title: 'Canceladas', count: cancelled }];

  return <><StoreHeader /><main className="container cu-page"><Link className="cu-back" href="/"><ArrowLeft size={15} /> Volver a la tienda</Link><div className="cu-heading"><div><span className="eyebrow">MI CUENTA</span><h1 className="page-title">Mis compras</h1><p className="muted">Seguí tus pedidos y encontrá los detalles de cada compra.</p></div>{store?.session && <div className="cu-user"><span className="cu-avatar"><UserRound size={20} /></span><div><strong>{store.session.name}</strong><small>{store.session.email}</small></div></div>}</div>
    {loading ? <div className="card cu-loading" role="status"><RefreshCw className="cu-spin" size={23} /><p>Cargando tu cuenta…</p></div> : !store ? <div className="card empty"><h2>No pudimos abrir tu cuenta</h2><p>{error || 'Intentá nuevamente en unos momentos.'}</p><button className="button" disabled={ordersLoading} onClick={() => void retry()}>{ordersLoading ? 'Cargando…' : 'Reintentar'}</button></div> : !store.session ? <div className="card cu-signin"><span className="cu-signin-icon"><LockKeyhole size={30} /></span><span className="eyebrow">TUS COMPRAS, A MANO</span><h2>Ingresá a tu cuenta</h2><p>Consultá tus compras, el estado de los pagos y el seguimiento de tus envíos.</p><a className="button" href="/signin-with-chatgpt?return_to=/cuenta" target="_top">Ingresar con ChatGPT <ArrowUpRight size={16} /></a><small>Tu historial está vinculado a la cuenta con la que compraste.</small></div> : <>
      {store.session.isAdmin && <div className="cu-admin-link"><span>También tenés acceso de administrador.</span><a href="/admin">Ir al panel <ArrowUpRight size={14} /></a></div>}
      {error && <div className="cu-error" role="alert">{error}</div>}
      {ordersLoading ? <div className="card cu-loading" role="status"><RefreshCw className="cu-spin" size={22} /> Consultando tus compras…</div> : orders === null ? <div className="card empty"><Package size={32} /><h2>No pudimos consultar tus compras</h2><p>Tu historial no está disponible en este momento. Reintentá para cargarlo.</p><button className="button" onClick={() => void retry()}>Reintentar</button></div> : !orders.length ? <div className="card cu-empty-orders"><span className="cu-empty-icon"><ShoppingBag size={34} /></span><h2>Tu próxima compra empieza acá</h2><p>Todavía no tenés pedidos en esta cuenta. Cuando hagas una compra, vas a poder seguirla desde este panel.</p><Link className="button" href="/">Explorar productos <ArrowUpRight size={15} /></Link></div> : <>
        <div className="cu-metrics"><article className="card cu-metric"><span className="cu-metric-icon"><ShoppingBag size={19} /></span><div><strong>{list.length}</strong><span>{list.length === 1 ? 'Compra realizada' : 'Compras realizadas'}</span></div></article><article className="card cu-metric"><span className="cu-metric-icon shipping"><Truck size={19} /></span><div><strong>{inProgress}</strong><span>{inProgress === 1 ? 'Pedido en curso' : 'Pedidos en curso'}</span></div></article><article className="card cu-metric"><span className="cu-metric-icon delivered"><CheckCircle2 size={19} /></span><div><strong>{delivered}</strong><span>{delivered === 1 ? 'Compra entregada' : 'Compras entregadas'}</span></div></article></div>
        <div className="cu-list-toolbar"><div className="cu-filters" role="group" aria-label="Filtrar compras por estado">{filters.map(item => <button key={item.id} aria-pressed={filter === item.id} className={filter === item.id ? 'active' : ''} onClick={() => setFilter(item.id)}>{item.title} <span>{item.count}</span></button>)}</div><button className="cu-refresh" onClick={() => void retry()}><RefreshCw size={13} /> Actualizar</button></div>
        {visible.length === 0 ? <div className="card empty"><Package size={28} /><h3>No hay compras en este estado</h3><p>Elegí otro filtro para consultar tus pedidos.</p><button className="button secondary" onClick={() => setFilter('all')}>Ver todas las compras</button></div> : <div className="cu-order-list">{visible.map(order => { const tracking = safeHttps(order.trackingUrl); const payment = safeHttps(order.paymentUrl); const subtotal = order.items.reduce((sum, item) => sum + item.unitPrice * item.quantity, 0); return <article className="card cu-order" key={order.id}><div className="cu-order-top"><div><span className="cu-order-number">Pedido {order.number}</span>{order.isDemo && <span className="cu-test">Prueba</span>}<time dateTime={order.createdAt}>{orderDate(order.createdAt)}</time></div><span className={`cu-state ${stateClass(order.status)}`}>{order.status}</span></div><div className="cu-order-body"><div className="cu-order-items">{order.items.map((item, index) => <div className="cu-order-item" key={`${item.productId}-${index}`}><div className="cu-product-image">{item.image ? <img src={item.image} alt="" width="74" height="74" loading="lazy" /> : <Package size={25} />}</div><div><h3>{item.name}</h3><p>{item.quantity} {item.quantity === 1 ? 'unidad' : 'unidades'} · {ars(item.unitPrice)} c/u</p><strong>{ars(item.unitPrice * item.quantity)}</strong></div></div>)}</div><div className="cu-order-summary"><div className="cu-summary-label">Total de la compra</div><strong className="cu-order-total">{ars(order.total)}</strong><span className="cu-includes-shipping">Incluye {ars(order.shippingCost)} de envío</span><div className={`cu-payment ${paymentComplete(order.paymentStatus) ? 'complete' : ''}`}><CreditCard size={15} /><span>Pago {paymentLabel(order.paymentStatus).toLocaleLowerCase('es')}</span></div>{payment && canPay(order) && <a className="button cu-payment-link" href={payment} target="_blank" rel="noopener noreferrer">Continuar pago <ArrowUpRight size={14} /></a>}</div></div><div className="cu-shipping-row"><span className="cu-shipping-icon"><Truck size={18} /></span><div><strong>{order.shippingMethod || 'Información de envío'}</strong><p>{order.trackingCode ? `Código de seguimiento: ${order.trackingCode}` : order.status === 'Cancelado' ? 'Este pedido fue cancelado.' : ['Enviado', 'Entregado'].includes(order.status) ? 'El vendedor todavía no informó el código de seguimiento.' : 'Vas a ver el seguimiento cuando tu pedido se despache.'}</p></div>{tracking && <a className="cu-track-link" href={tracking} target="_blank" rel="noopener noreferrer">Seguir envío <ArrowUpRight size={14} /></a>}</div><details className="cu-details"><summary>Ver detalle de la compra <ChevronDown size={16} /></summary><div className="cu-details-grid"><section><h4>Domicilio de entrega</h4><p>{order.address.name || order.customerName}</p><p>{order.address.street} {order.address.number}{order.address.floor ? `, ${order.address.floor}` : ''}</p><p>{order.address.city}, {order.address.state}</p><p>Código postal {order.address.postcode}</p>{order.address.phone && <p>Teléfono: {order.address.phone}</p>}</section><section><h4>Resumen del pago</h4><div className="cu-detail-total"><span>Productos</span><strong>{ars(subtotal)}</strong></div><div className="cu-detail-total"><span>Envío</span><strong>{ars(order.shippingCost)}</strong></div><div className="cu-detail-total final"><span>Total</span><strong>{ars(order.total)}</strong></div><p className="cu-payment-detail">Estado del pago: {paymentLabel(order.paymentStatus)}</p></section></div></details></article>; })}</div>}
        <p className="cu-privacy"><LockKeyhole size={12} /> Este historial muestra únicamente las compras de tu cuenta. Los pedidos de ejemplo llevan la etiqueta Prueba.</p>
      </>}
    </>}
  </main></>;
}
