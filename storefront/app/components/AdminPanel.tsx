'use client';

import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import Link from 'next/link';
import { ArrowLeft, ArrowUpRight, ChevronDown, ChevronUp, FolderTree, Image as ImageIcon, LockKeyhole, Package, Plug, Plus, RefreshCw, ShoppingBag, Upload, X } from 'lucide-react';
import StoreHeader from './StoreHeader';
import { ars, categoryPath, type Order, type Product, type ProductMedia, type StoreData } from '../lib/domain';

type Tab = 'products' | 'categories' | 'orders' | 'integrations';
type CategoryDraft = { id: string; name: string; parentId: string };
type OrderDraft = { status: string; trackingCode: string; trackingUrl: string };
const statuses = ['Pendiente', 'Preparando', 'Enviado', 'Entregado', 'Cancelado'];
const blankProduct = (): Product => ({ id: '', sku: '', name: '', brand: '', description: '', price: 0, stock: 0, categoryId: '', active: true, featured: false, weightGrams: 0, heightCm: 0, widthCm: 0, lengthCm: 0, media: [] });

async function api<T>(url: string, options?: RequestInit): Promise<T> {
  const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
  const body: unknown = await response.json().catch(() => null);
  const problem = body && typeof body === 'object' ? body as { error?: unknown; message?: unknown } : null;
  if (!response.ok) throw new Error(typeof problem?.error === 'string' ? problem.error : typeof problem?.message === 'string' ? problem.message : `No pudimos completar la solicitud (${response.status}).`);
  if (body === null) throw new Error('El servidor devolvió una respuesta inesperada.');
  return body as T;
}

function errorMessage(error: unknown) { return error instanceof Error ? error.message : 'Ocurrió un error. Intentá nuevamente.'; }
function safeHttps(value: string | null | undefined) { try { const url = new URL(value || ''); return url.protocol === 'https:' ? url.href : null; } catch { return null; } }
function date(value: string) { const result = new Date(value); return Number.isNaN(result.getTime()) ? value : result.toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' }); }

async function squareImage(file: File): Promise<File> {
  const url = URL.createObjectURL(file);
  try {
    const image = new window.Image();
    await new Promise<void>((resolve, reject) => { image.onload = () => resolve(); image.onerror = () => reject(new Error('No pudimos leer esta imagen. Usá un archivo JPG, PNG o WebP.')); image.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = 600; canvas.height = 600;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Este navegador no pudo preparar la imagen.');
    context.fillStyle = '#ffffff'; context.fillRect(0, 0, 600, 600);
    const scale = Math.min(600 / image.naturalWidth, 600 / image.naturalHeight);
    const width = image.naturalWidth * scale, height = image.naturalHeight * scale;
    context.drawImage(image, (600 - width) / 2, (600 - height) / 2, width, height);
    const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(result => result ? resolve(result) : reject(new Error('No pudimos convertir la imagen.')), 'image/jpeg', .9));
    return new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.jpg`, { type: 'image/jpeg' });
  } finally { URL.revokeObjectURL(url); }
}

function uploadFile(file: File, onProgress: (value: number) => void): Promise<ProductMedia> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('POST', '/api/media');
    request.withCredentials = true;
    request.upload.onprogress = event => { if (event.lengthComputable) onProgress(Math.round(event.loaded / event.total * 100)); };
    request.onerror = () => reject(new Error('Se interrumpió la conexión durante la carga. Intentá nuevamente.'));
    request.onabort = () => reject(new Error('La carga se canceló.'));
    request.onload = () => {
      let body: { media?: ProductMedia; error?: string } | null = null;
      try { body = JSON.parse(request.responseText); } catch { /* Handled below. */ }
      if (request.status < 200 || request.status >= 300 || !body?.media) reject(new Error(body?.error || 'No pudimos guardar el archivo.'));
      else resolve(body.media);
    };
    const form = new FormData(); form.append('file', file); request.send(form);
  });
}

export default function AdminPanel() {
  const [store, setStore] = useState<StoreData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [tab, setTab] = useState<Tab>('products');
  const [search, setSearch] = useState('');
  const [product, setProduct] = useState<Product | null>(null);
  const [category, setCategory] = useState<CategoryDraft | null>(null);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('');
  const [orders, setOrders] = useState<Order[] | null>(null);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [orderDrafts, setOrderDrafts] = useState<Record<string, OrderDraft>>({});
  const [savingOrder, setSavingOrder] = useState<string | null>(null);
  const productDialog = useRef<HTMLDialogElement>(null);
  const categoryDialog = useRef<HTMLDialogElement>(null);
  const hasProduct = product !== null;
  const hasCategory = category !== null;

  async function loadStore() { const data = await api<StoreData>('/api/store'); setStore(data); return data; }
  async function refresh() { setLoading(true); setError(''); try { await loadStore(); } catch (failure) { setError(errorMessage(failure)); } finally { setLoading(false); } }
  async function loadOrders() {
    setOrdersLoading(true); setError('');
    try { const data = await api<{ orders: Order[] }>('/api/admin/orders'); setOrders(data.orders); setOrderDrafts({}); }
    catch (failure) { setOrders(null); setError(errorMessage(failure)); }
    finally { setOrdersLoading(false); }
  }

  useEffect(() => {
    const controller = new AbortController();
    api<StoreData>('/api/store', { signal: controller.signal }).then(setStore).catch(failure => { if (!controller.signal.aborted) setError(errorMessage(failure)); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, []);
  useEffect(() => { if (hasProduct && !productDialog.current?.open) productDialog.current?.showModal(); }, [hasProduct]);
  useEffect(() => { if (hasCategory && !categoryDialog.current?.open) categoryDialog.current?.showModal(); }, [hasCategory]);

  function changeTab(next: Tab) { setTab(next); setError(''); setNotice(''); if (next === 'orders' && orders === null && store?.session?.isAdmin) void loadOrders(); }
  function navigateTab(event: KeyboardEvent<HTMLButtonElement>, current: Tab) {
    const ids: Tab[] = ['products', 'categories', 'orders', 'integrations'];
    const index = ids.indexOf(current);
    const destination = event.key === 'ArrowRight' ? (index + 1) % ids.length : event.key === 'ArrowLeft' ? (index + ids.length - 1) % ids.length : event.key === 'Home' ? 0 : event.key === 'End' ? ids.length - 1 : -1;
    if (destination < 0) return;
    event.preventDefault(); changeTab(ids[destination]); document.getElementById(`ad-tab-${ids[destination]}`)?.focus();
  }
  function editProduct(next: Product | null) { setError(''); setNotice(''); setProduct(next ? { ...next, media: next.media.map(item => ({ ...item })) } : { ...blankProduct(), categoryId: store?.categories[0]?.id || '' }); }
  function updateProduct<K extends keyof Product>(key: K, value: Product[K]) { setProduct(current => current ? { ...current, [key]: value } : null); }

  async function saveProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!product || saving || uploading) return;
    setSaving(true); setError('');
    try {
      await api('/api/admin/products', { method: product.id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...product, id: product.id || undefined, name: product.name.trim(), sku: product.sku.trim(), brand: product.brand.trim() }) });
      setProduct(null); setNotice('Producto guardado.');
      try { await loadStore(); } catch { setError('El producto se guardó, pero no pudimos actualizar el listado. Recargá para ver los cambios.'); }
    } catch (failure) { setError(errorMessage(failure)); }
    finally { setSaving(false); }
  }

  async function addFiles(files: FileList | null) {
    if (!files || !product || uploading) return;
    setUploading(true); setError(''); const selected = Array.from(files);
    try {
      for (let index = 0; index < selected.length; index++) {
        const file = selected[index]; const isImage = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
        const isVideo = ['video/mp4', 'video/webm'].includes(file.type);
        if (!isImage && !isVideo) throw new Error(`${file.name}: usá JPG, PNG, WebP, MP4 o WebM.`);
        if (file.size > (isImage ? 5 : 30) * 1024 * 1024) throw new Error(`${file.name}: el límite es ${isImage ? '5 MB por imagen' : '30 MB por video'}.`);
        setUploadProgress(0); setUploadLabel(`${isImage ? 'Preparando imagen' : 'Subiendo video'} ${index + 1} de ${selected.length}`);
        const prepared = isImage ? await squareImage(file) : file;
        setUploadLabel(`Subiendo archivo ${index + 1} de ${selected.length}`);
        const media = await uploadFile(prepared, setUploadProgress);
        setProduct(current => current ? { ...current, media: [...current.media, { ...media, alt: current.name || file.name.replace(/\.[^.]+$/, '') }] } : null);
      }
      setNotice('Archivos cargados. Guardá el producto para publicar la galería.');
    } catch (failure) { setError(errorMessage(failure)); }
    finally { setUploading(false); setUploadLabel(''); }
  }

  function moveMedia(index: number, destination: number) {
    setProduct(current => { if (!current) return null; const media = [...current.media]; const [item] = media.splice(index, 1); media.splice(destination, 0, item); return { ...current, media }; });
  }

  function blockedParents(id: string): Set<string> {
    const blocked = new Set<string>(id ? [id] : []); let changed = true;
    while (changed) { changed = false; store?.categories.forEach(item => { if (item.parentId && blocked.has(item.parentId) && !blocked.has(item.id)) { blocked.add(item.id); changed = true; } }); }
    return blocked;
  }

  async function saveCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!category || saving) return; setSaving(true); setError('');
    try {
      await api('/api/admin/categories', { method: category.id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: category.id || undefined, name: category.name.trim(), parentId: category.parentId || null }) });
      setCategory(null); setNotice('Categoría guardada.');
      try { await loadStore(); } catch { setError('La categoría se guardó, pero no pudimos actualizar el listado. Recargá para ver los cambios.'); }
    } catch (failure) { setError(errorMessage(failure)); }
    finally { setSaving(false); }
  }

  async function saveOrder(event: FormEvent<HTMLFormElement>, order: Order) {
    event.preventDefault(); if (savingOrder) return;
    const draft = orderDrafts[order.id] || { status: order.status, trackingCode: order.trackingCode || '', trackingUrl: order.trackingUrl || '' };
    if (draft.trackingUrl.trim() && !safeHttps(draft.trackingUrl.trim())) { setError('El enlace de seguimiento debe ser una dirección HTTPS válida.'); return; }
    setSavingOrder(order.id); setError('');
    try {
      await api('/api/admin/orders', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: order.id, ...draft, trackingCode: draft.trackingCode.trim(), trackingUrl: draft.trackingUrl.trim() }) });
      setNotice(`Pedido ${order.number} actualizado.`);
      await loadOrders();
    } catch (failure) { setError(errorMessage(failure)); }
    finally { setSavingOrder(null); }
  }

  async function generateShipment(order: Order) {
    if (savingOrder) return;
    setSavingOrder(order.id); setError('');
    try {
      const data = await api<{ order: Order; shipmentId: string }>('/api/admin/shipments', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: order.id }) });
      setOrders(current => current?.map(item => item.id === order.id ? data.order : item) || null);
      setOrderDrafts(current => { const next = { ...current }; delete next[order.id]; return next; });
      setNotice(`Envío ${data.shipmentId} generado en Zipnova. El estado del pedido se mantiene hasta que lo despaches.`);
    } catch (failure) { setError(errorMessage(failure)); }
    finally { setSavingOrder(null); }
  }

  const categories = [...(store?.categories || [])].sort((a, b) => categoryPath(store?.categories || [], a.id).localeCompare(categoryPath(store?.categories || [], b.id), 'es'));
  const visibleProducts = (store?.products || []).filter(item => `${item.name} ${item.sku} ${item.brand}`.toLocaleLowerCase('es').includes(search.toLocaleLowerCase('es')));
  const nav = [{ id: 'products', title: 'Productos', icon: Package }, { id: 'categories', title: 'Categorías', icon: FolderTree }, { id: 'orders', title: 'Pedidos', icon: ShoppingBag }, { id: 'integrations', title: 'Integraciones', icon: Plug }] as const;

  return <><StoreHeader /><main className="container ad-page">
    <Link className="ad-back" href="/"><ArrowLeft size={15} /> Volver a la tienda</Link>
    <div className="ad-heading"><div><span className="eyebrow">GESTIÓN DE TU TIENDA</span><h1 className="page-title">Administración</h1><p className="muted">Tu catálogo, tus pedidos y tus conexiones, en un solo lugar.</p></div>{store?.session?.isAdmin && <span className="ad-access"><LockKeyhole size={14} /> Administrador</span>}</div>
    {loading ? <div className="card ad-loading" role="status"><RefreshCw className="ad-spin" size={23} /><p>Cargando tu panel…</p></div> : !store ? <div className="card empty"><h2>No pudimos abrir el panel</h2><p>{error || 'Intentá nuevamente en unos momentos.'}</p><button className="button" onClick={() => void refresh()}>Reintentar</button></div> : !store.session ? <div className="card empty"><LockKeyhole size={32} /><h2>Ingresá para administrar tu tienda</h2><p>El panel está disponible para las cuentas autorizadas.</p><a className="button" href="/signin-with-chatgpt?return_to=/cuenta" target="_top">Ingresar con ChatGPT</a></div> : !store.session.isAdmin ? <div className="card empty"><LockKeyhole size={32} /><h2>Este panel requiere acceso de administrador</h2><p>Tu cuenta puede consultar sus compras desde Mi cuenta.</p><a className="button" href="/cuenta">Ir a mis compras</a></div> : <>
      {store.isDemo && <div className="ad-demo">Estás viendo productos de ejemplo. Los pedidos de prueba se identifican por separado.</div>}
      <div className="ad-tabs" role="tablist" aria-label="Secciones de administración">{nav.map(item => <button key={item.id} role="tab" tabIndex={tab === item.id ? 0 : -1} onKeyDown={event => navigateTab(event, item.id)} id={`ad-tab-${item.id}`} aria-controls={`ad-panel-${item.id}`} aria-selected={tab === item.id} className={tab === item.id ? 'active' : ''} onClick={() => changeTab(item.id)}><item.icon size={17} />{item.title}</button>)}</div>
      {error && !product && !category && <div className="ad-message ad-error" role="alert">{error}</div>}
      {notice && !product && !category && <div className="ad-message ad-success" role="status">{notice}</div>}
      <section role="tabpanel" id={`ad-panel-${tab}`} aria-labelledby={`ad-tab-${tab}`}>
        {tab === 'products' && <div className="card ad-section"><div className="ad-section-heading"><div><h2>Catálogo de productos <span>{store.products.length}</span></h2><p className="muted">Precios, stock y contenido de cada publicación.</p></div><button className="button" onClick={() => editProduct(null)}><Plus size={16} /> Nuevo producto</button></div><label className="ad-search field"><span className="ad-sr-only">Buscar productos</span><input type="search" placeholder="Buscar por producto, marca o SKU" value={search} onChange={event => setSearch(event.target.value)} /></label>{visibleProducts.length ? <div className="ad-table-wrap"><table className="ad-table"><thead><tr><th>Producto</th><th>Categoría</th><th>Precio</th><th>Stock</th><th>Estado</th><th><span className="ad-sr-only">Acciones</span></th></tr></thead><tbody>{visibleProducts.map(item => <tr key={item.id}><td><div className="ad-product-cell"><div className="ad-thumb">{item.media[0]?.type === 'image' ? <img src={item.media[0].url} alt="" width="58" height="58" /> : item.media[0]?.type === 'video' ? <video src={item.media[0].url} muted playsInline preload="metadata" aria-label={`Video de ${item.name}`} /> : <ImageIcon size={22} />}</div><div><strong>{item.name}</strong><small>{item.brand}{item.brand && item.sku ? ' · ' : ''}{item.sku || 'Sin SKU'}</small></div></div></td><td className="ad-category-cell">{categoryPath(store.categories, item.categoryId) || 'Sin categoría'}</td><td className="ad-price">{ars(item.price)}</td><td><span className={item.stock === 0 ? 'ad-out-of-stock' : ''}>{item.stock} {item.stock === 1 ? 'unidad' : 'unidades'}</span></td><td><span className={`status-pill ${item.active ? '' : 'ad-paused'}`}>{item.active ? 'Activo' : 'Pausado'}</span></td><td><button className="ad-edit" onClick={() => editProduct(item)} aria-label={`Editar ${item.name}`}>Editar <ArrowUpRight size={14} /></button></td></tr>)}</tbody></table></div> : <div className="empty"><Package size={30} /><h3>{search ? 'No encontramos productos' : 'Tu catálogo está listo para empezar'}</h3><p>{search ? 'Probá con otro nombre, marca o SKU.' : 'Creá tu primer producto con fotos, precio y stock.'}</p></div>}</div>}
        {tab === 'categories' && <div className="card ad-section"><div className="ad-section-heading"><div><h2>Categorías y subcategorías <span>{store.categories.length}</span></h2><p className="muted">Organizá tu catálogo en tantos niveles como necesites.</p></div><button className="button" onClick={() => { setError(''); setCategory({ id: '', name: '', parentId: '' }); }}><Plus size={16} /> Nueva categoría</button></div>{categories.length ? <div className="ad-category-list">{categories.map(item => <div className="ad-category-row" key={item.id}><span className="ad-folder"><FolderTree size={18} /></span><div><strong>{item.name}</strong><small>{categoryPath(store.categories, item.id)}</small></div><button className="ad-edit" aria-label={`Editar categoría ${item.name}`} onClick={() => { setError(''); setCategory({ id: item.id, name: item.name, parentId: item.parentId || '' }); }}>Editar <ArrowUpRight size={14} /></button></div>)}</div> : <div className="empty"><FolderTree size={30} /><h3>Creá la primera categoría</h3><p>Después podrás asignarla a tus productos y agregar subcategorías.</p></div>}</div>}
        {tab === 'orders' && <div className="ad-orders-section"><div className="ad-section-heading"><div><h2>Pedidos recibidos</h2><p className="muted">Prepará cada compra y compartí su seguimiento.</p></div><button className="button secondary" disabled={ordersLoading || !!savingOrder} onClick={() => void loadOrders()}><RefreshCw size={15} className={ordersLoading ? 'ad-spin' : ''} /> Actualizar</button></div>{ordersLoading ? <div className="card ad-loading" role="status">Cargando pedidos…</div> : orders === null ? <div className="card empty"><h3>No pudimos cargar los pedidos</h3><p>Reintentá para consultar la información del servidor.</p><button className="button" onClick={() => void loadOrders()}>Reintentar</button></div> : !orders.length ? <div className="card empty"><ShoppingBag size={32} /><h3>Todavía no hay pedidos</h3><p>Las nuevas compras aparecerán acá cuando tus clientes finalicen el checkout.</p></div> : orders.map(order => { const draft = orderDrafts[order.id] || { status: order.status, trackingCode: order.trackingCode || '', trackingUrl: order.trackingUrl || '' }; const changeDraft = (key: keyof OrderDraft, value: string) => setOrderDrafts(current => ({ ...current, [order.id]: { ...draft, [key]: value } })); return <article className="card ad-order" key={order.id}><div className="ad-order-heading"><div><strong>Pedido {order.number}</strong>{order.isDemo && <span className="ad-test">Prueba</span>}<small>{date(order.createdAt)} · {order.customerName}</small></div><div className="ad-order-total"><strong>{ars(order.total)}</strong><small>Pago: {order.paymentStatus}</small></div></div><details className="ad-order-details"><summary>Ver productos y datos de entrega</summary><div className="ad-order-detail-grid"><div><h4>Productos</h4>{order.items.map((item, index) => <p key={`${item.productId}-${index}`}>{item.quantity} × {item.name} <strong>{ars(item.unitPrice * item.quantity)}</strong></p>)}</div><div><h4>Entrega</h4><p>{order.shippingMethod || 'Sin método de envío'} · {ars(order.shippingCost)}</p><p>{order.address.street} {order.address.number}{order.address.floor ? `, ${order.address.floor}` : ''}</p><p>{order.address.city}, {order.address.state} · CP {order.address.postcode}</p><p>{order.customerEmail}</p></div></div></details><form className="ad-order-form" onSubmit={event => void saveOrder(event, order)}><label className="field">Estado<select value={draft.status} onChange={event => changeDraft('status', event.target.value)}>{statuses.map(status => <option key={status}>{status}</option>)}</select></label><label className="field">Código de seguimiento<input value={draft.trackingCode} maxLength={150} onChange={event => changeDraft('trackingCode', event.target.value)} placeholder="Opcional" /></label><label className="field">Enlace de seguimiento<input type="url" value={draft.trackingUrl} onChange={event => changeDraft('trackingUrl', event.target.value)} placeholder="https://…" /></label><button className="button" type="submit" disabled={!!savingOrder}>{savingOrder === order.id ? 'Guardando…' : 'Guardar estado'}</button></form>{!order.isDemo && order.paymentStatus === 'Aprobado' && !order.shippingMethod.toLocaleLowerCase('es').includes('retiro') && !order.trackingCode && !order.trackingUrl && order.status !== 'Cancelado' && order.status !== 'Entregado' && <div className="ad-shipment-actions"><p className="muted">Generá el envío en Zipnova antes de preparar el despacho.</p><button type="button" className="button secondary" disabled={!!savingOrder} onClick={() => void generateShipment(order)}>{savingOrder === order.id ? 'Procesando…' : 'Generar envío'}</button></div>}</article>; })}</div>}
        {tab === 'integrations' && <><div className="ad-integration-intro"><h2>Conexiones que hacen funcionar tu tienda</h2><p className="muted">Las credenciales se configuran en el servidor. Este panel muestra su disponibilidad.</p></div><div className="ad-integrations"><article className="card ad-integration"><div className="ad-integration-brand ad-mp">MP</div><div className="ad-integration-heading"><h3>Mercado Pago</h3><span className={`ad-connection ${store.integration.mercadopago ? 'ready' : ''}`}>{store.integration.mercadopago ? 'Configurado' : 'Configuración pendiente'}</span></div><p>Cobrá las compras de tus clientes con un checkout seguro y recibí las actualizaciones de pago.</p><div className="ad-config"><span>Claves del servidor</span><code>MERCADOPAGO_ACCESS_TOKEN</code><code>MERCADOPAGO_WEBHOOK_SECRET</code></div><small>Usá las credenciales de tu cuenta vendedora y configurá el webhook de pagos. Las claves privadas nunca se ingresan en este panel.</small></article><article className="card ad-integration"><div className="ad-integration-brand ad-zip">Z</div><div className="ad-integration-heading"><h3>Zipnova <small>(antes Zippin)</small></h3><span className={`ad-connection ${store.integration.zipnova ? 'ready' : ''}`}>{store.integration.zipnova ? 'Configurado' : 'Configuración pendiente'}</span></div><p>Cotizá el envío de cada pedido según el domicilio, el peso y las dimensiones de sus productos.</p><div className="ad-config"><span>Claves del servidor</span><code>ZIPNOVA_API_TOKEN</code><code>ZIPNOVA_API_SECRET</code><code>ZIPNOVA_ACCOUNT_ID</code><code>ZIPNOVA_ORIGIN_ID</code></div><small>El origen debe corresponder al lugar desde el que despachás. Código postal de origen: <strong>{store.settings.originPostcode || 'Pendiente'}</strong>. La clave opcional es <code>ZIPNOVA_ORIGIN_POSTCODE</code>.</small></article></div><div className="card ad-integration-access"><LockKeyhole size={21} /><div><h3>Acceso al panel</h3><p>Las cuentas autorizadas se definen con <code>ADMIN_EMAILS</code> en el servidor, separadas por comas. Tu sesión actual: <strong>{store.session.email}</strong>.</p></div></div></>}
      </section>
    </>}
    {product && <dialog className="ad-dialog" ref={productDialog} aria-labelledby="ad-product-title" onCancel={event => { if (uploading || saving) event.preventDefault(); else setProduct(null); }}><form onSubmit={event => void saveProduct(event)}><div className="ad-dialog-heading"><div><span className="eyebrow">CATÁLOGO</span><h2 id="ad-product-title">{product.id ? 'Editar producto' : 'Nuevo producto'}</h2></div><button type="button" className="ad-icon-button" disabled={uploading || saving} onClick={() => setProduct(null)} aria-label="Cerrar formulario"><X size={21} /></button></div>{error && <div className="ad-message ad-error" role="alert">{error}</div>}{notice && <div className="ad-message ad-success" role="status">{notice}</div>}<div className="ad-form-grid"><label className="field ad-full">Nombre del producto<input required maxLength={150} value={product.name} onChange={event => updateProduct('name', event.target.value)} placeholder="Por ejemplo, Auriculares inalámbricos" /></label><label className="field">SKU<input required maxLength={50} value={product.sku} onChange={event => updateProduct('sku', event.target.value)} placeholder="Código interno" /></label><label className="field">Marca<input maxLength={80} value={product.brand} onChange={event => updateProduct('brand', event.target.value)} /></label><label className="field">Precio (ARS)<input type="number" required min="0.01" max="999999999" step="0.01" value={product.price || ''} onChange={event => updateProduct('price', Number(event.target.value))} /></label><label className="field">Stock<input type="number" required min="0" max="999999" step="1" value={product.stock} onChange={event => updateProduct('stock', Number(event.target.value))} /></label><label className="field ad-full">Categoría<select required value={product.categoryId} onChange={event => updateProduct('categoryId', event.target.value)}><option value="">Elegí una categoría</option>{categories.map(item => <option key={item.id} value={item.id}>{categoryPath(store?.categories || [], item.id)}</option>)}</select>{!categories.length && <small>Creá una categoría antes de guardar el producto.</small>}</label><label className="field ad-full">Descripción<textarea rows={4} maxLength={10000} value={product.description} onChange={event => updateProduct('description', event.target.value)} placeholder="Contá sus características y qué lo hace especial." /></label></div><div className="ad-form-flags"><label><input type="checkbox" checked={product.active} onChange={event => updateProduct('active', event.target.checked)} /> Producto activo</label><label><input type="checkbox" checked={product.featured} onChange={event => updateProduct('featured', event.target.checked)} /> Destacado en la tienda</label></div><fieldset className="ad-fieldset"><legend>Medidas para el envío</legend><p className="muted">Ingresá los datos del producto embalado para calcular el envío.</p><div className="ad-measurements">{([{ key: 'weightGrams', label: 'Peso (g)', step: '1' }, { key: 'heightCm', label: 'Alto (cm)', step: '0.1' }, { key: 'widthCm', label: 'Ancho (cm)', step: '0.1' }, { key: 'lengthCm', label: 'Largo (cm)', step: '0.1' }] as const).map(item => <label key={item.key} className="field">{item.label}<input type="number" required min={item.key === 'weightGrams' ? '1' : '0.1'} max={item.key === 'weightGrams' ? '10000000' : '5000'} step={item.step} value={product[item.key] || ''} onChange={event => updateProduct(item.key, Number(event.target.value))} /></label>)}</div></fieldset><fieldset className="ad-fieldset"><legend>Fotos y videos</legend><p className="muted">Galería cuadrada 1:1. Las imágenes se convierten a 600 × 600 px con fondo blanco. Los videos conservan su archivo original.</p><label className={`ad-upload ${uploading ? 'busy' : ''}`}><Upload size={21} /><strong>{uploading ? uploadLabel : 'Agregar fotos o videos'}</strong><span>JPG, PNG o WebP hasta 5 MB · MP4 o WebM hasta 30 MB</span><input type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" multiple disabled={uploading || saving} onChange={event => { void addFiles(event.target.files); event.target.value = ''; }} /></label>{uploading && <div className="ad-progress" role="status"><progress value={uploadProgress} max="100" aria-label="Progreso de carga" /><span>{uploadProgress}% {uploadProgress === 100 ? '· Procesando en el servidor…' : ''}</span></div>}{product.media.length > 0 && <div className="ad-media-list">{product.media.map((media, index) => <div className="ad-media-item" key={media.id}><div className="ad-media-preview">{media.type === 'image' ? <img src={media.url} alt={media.alt || product.name} width="600" height="600" /> : <video src={media.url} controls playsInline preload="metadata" aria-label={media.alt || `Video de ${product.name}`} />}{index === 0 && <span className="ad-cover-badge">Portada</span>}</div><div className="ad-media-content"><label className="field">Descripción {media.type === 'image' ? 'de la imagen' : 'del video'} {index + 1}<input maxLength={180} value={media.alt} onChange={event => setProduct(current => current ? { ...current, media: current.media.map((item, itemIndex) => itemIndex === index ? { ...item, alt: event.target.value } : item) } : null)} /></label><div className="ad-media-actions"><button type="button" className="ad-small-button" disabled={index === 0 || uploading} onClick={() => moveMedia(index, 0)}>Usar de portada</button><button type="button" className="ad-icon-button" disabled={index === 0 || uploading} aria-label={`Mover archivo ${index + 1} antes`} onClick={() => moveMedia(index, index - 1)}><ChevronUp size={16} /></button><button type="button" className="ad-icon-button" disabled={index === product.media.length - 1 || uploading} aria-label={`Mover archivo ${index + 1} después`} onClick={() => moveMedia(index, index + 1)}><ChevronDown size={16} /></button><button type="button" className="ad-icon-button ad-remove" disabled={uploading} aria-label={`Quitar archivo ${index + 1}`} onClick={() => updateProduct('media', product.media.filter((_, itemIndex) => itemIndex !== index))}><X size={16} /></button></div></div></div>)}</div>}</fieldset><div className="ad-dialog-actions"><button type="button" className="button secondary" disabled={saving || uploading} onClick={() => setProduct(null)}>Cancelar</button><button className="button" type="submit" disabled={saving || uploading || !categories.length}>{saving ? 'Guardando…' : 'Guardar producto'}</button></div></form></dialog>}
    {category && <dialog className="ad-dialog ad-category-dialog" ref={categoryDialog} aria-labelledby="ad-category-title" onCancel={event => { if (saving) event.preventDefault(); else setCategory(null); }}><form onSubmit={event => void saveCategory(event)}><div className="ad-dialog-heading"><div><span className="eyebrow">ORGANIZACIÓN DEL CATÁLOGO</span><h2 id="ad-category-title">{category.id ? 'Editar categoría' : 'Nueva categoría'}</h2></div><button type="button" className="ad-icon-button" disabled={saving} onClick={() => setCategory(null)} aria-label="Cerrar formulario"><X size={21} /></button></div>{error && <div className="ad-message ad-error" role="alert">{error}</div>}<label className="field">Nombre<input required maxLength={100} value={category.name} onChange={event => setCategory({ ...category, name: event.target.value })} placeholder="Por ejemplo, Accesorios" /></label><label className="field">Categoría superior<select value={category.parentId} onChange={event => setCategory({ ...category, parentId: event.target.value })}><option value="">Ninguna · categoría principal</option>{categories.filter(item => !blockedParents(category.id).has(item.id)).map(item => <option key={item.id} value={item.id}>{categoryPath(store?.categories || [], item.id)}</option>)}</select></label><p className="ad-form-note">Elegí una categoría superior para crear una subcategoría. Podés anidar más niveles sin límite de profundidad.</p><div className="ad-dialog-actions"><button type="button" className="button secondary" disabled={saving} onClick={() => setCategory(null)}>Cancelar</button><button className="button" type="submit" disabled={saving}>{saving ? 'Guardando…' : 'Guardar categoría'}</button></div></form></dialog>}
  </main></>;
}
