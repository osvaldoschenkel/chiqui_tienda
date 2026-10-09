'use client';

import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowRight, Check, ChevronRight, CircleCheck, Cpu, CreditCard, Headphones, Keyboard, LoaderCircle, MapPin, Minus, Monitor, Mouse, Package, Plus, Search, ShieldCheck, ShoppingBag, SlidersHorizontal, Trash2, Truck, UserRound, X } from 'lucide-react';
import Link from 'next/link';
import StoreHeader from './StoreHeader';
import ProductGallery from './ProductGallery';
import { ars, categoryPath } from '../lib/domain';
import type { Address, CartLine, Category, Order, Product, ShippingOption, StoreData } from '../lib/domain';

const emptyAddress:Address={name:'',email:'',phone:'',document:'',street:'',number:'',floor:'',city:'',state:'',postcode:''};
const pickup:ShippingOption={id:'pickup',carrier:'Chiqui',service:'Retiro a coordinar',price:0,estimatedDelivery:'Coordinamos el retiro después de la compra'};
const provinces=['Buenos Aires','Ciudad Autónoma de Buenos Aires','Catamarca','Chaco','Chubut','Córdoba','Corrientes','Entre Ríos','Formosa','Jujuy','La Pampa','La Rioja','Mendoza','Misiones','Neuquén','Río Negro','Salta','San Juan','San Luis','Santa Cruz','Santa Fe','Santiago del Estero','Tierra del Fuego','Tucumán'];

function categoryDescendants(categories:Category[],id:string) {
  const included=new Set([id]);
  for(let changed=true;changed;) {changed=false;for(const category of categories) if(category.parentId&&included.has(category.parentId)&&!included.has(category.id)){included.add(category.id);changed=true;}}
  return included;
}

function useDialog(open:boolean,onClose:()=>void) {
  const ref=useRef<HTMLDivElement>(null);
  const closeRef=useRef(onClose);
  useEffect(()=>{closeRef.current=onClose;},[onClose]);
  useEffect(()=>{
    if(!open) return;
    const previous=document.activeElement as HTMLElement|null;
    const overflow=document.body.style.overflow;
    document.body.style.overflow='hidden';
    ref.current?.querySelector<HTMLElement>('button, input, select, a')?.focus();
    function key(event:KeyboardEvent) {
      if(event.key==='Escape') {event.preventDefault();closeRef.current();}
      if(event.key!=='Tab') return;
      const focusable=ref.current?.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href], textarea:not(:disabled)');
      if(!focusable?.length) return;
      const first=focusable[0],last=focusable[focusable.length-1];
      if(event.shiftKey&&document.activeElement===first) {event.preventDefault();last.focus();}
      else if(!event.shiftKey&&document.activeElement===last) {event.preventDefault();first.focus();}
    }
    document.addEventListener('keydown',key);
    return ()=>{document.body.style.overflow=overflow;document.removeEventListener('keydown',key);previous?.focus();};
  },[open]);
  return ref;
}

function ProductPhoto({product}:{product:Product}) {
  const item=product.media.find(entry=>entry.type==='image');
  return item?<img src={item.url} alt={item.alt||product.name} width={600} height={600} loading="lazy"/>:<div className="st-product-placeholder"><Monitor size={74} strokeWidth={1}/></div>;
}

function ProductDetail({product,categories,onClose,onAdd}:{product:Product;categories:Category[];onClose:()=>void;onAdd:(product:Product,quantity:number)=>void}) {
  const [quantity,setQuantity]=useState(1);
  const ref=useDialog(true,onClose);
  return <div className="st-overlay" onClick={event=>{if(event.target===event.currentTarget) onClose();}}><div ref={ref} role="dialog" aria-modal="true" aria-labelledby="st-detail-title" className="st-dialog st-detail-dialog"><button className="st-close" type="button" onClick={onClose} aria-label="Cerrar detalle"><X size={23}/></button><div className="st-detail-grid"><ProductGallery key={product.id} media={product.media} name={product.name}/><div className="st-detail-info"><p className="st-breadcrumb">{categoryPath(categories,product.categoryId)}</p><p className="st-product-brand">{product.brand} · SKU {product.sku}</p><h2 id="st-detail-title">{product.name}</h2><span className={`st-stock ${product.stock?'':'unavailable'}`}>{product.stock?<><CircleCheck size={15}/> Disponible · {product.stock} unidades</>:<>Sin stock</>}</span><p className="st-detail-price">{ars(product.price)}</p><p className="st-price-note">Precio final en pesos argentinos</p><div className="st-detail-add"><div className="st-quantity"><button type="button" onClick={()=>setQuantity(Math.max(1,quantity-1))} disabled={quantity<=1} aria-label="Restar unidad"><Minus size={15}/></button><output aria-label="Cantidad">{quantity}</output><button type="button" onClick={()=>setQuantity(Math.min(product.stock,quantity+1))} disabled={quantity>=product.stock} aria-label="Sumar unidad"><Plus size={15}/></button></div><button className="button" type="button" disabled={!product.stock} onClick={()=>{onAdd(product,quantity);onClose();}}><ShoppingBag size={18}/> Agregar al carrito</button></div><div className="st-detail-assurance"><Truck size={20}/><span>Envíos a todo el país<small>Cotizá con tu código postal al comprar.</small></span></div><div className="st-detail-description"><h3>Descripción</h3><p>{product.description}</p></div><div className="st-specs"><div><Package size={17}/><span>Peso<strong>{product.weightGrams.toLocaleString('es-AR')} g</strong></span></div><div><SlidersHorizontal size={17}/><span>Alto × ancho × largo<strong>{product.heightCm} × {product.widthCm} × {product.lengthCm} cm</strong></span></div></div></div></div></div></div>;
}

function Checkout({data,cart,onClose,onComplete}:{data:StoreData;cart:CartLine[];onClose:()=>void;onComplete:(order:Order)=>void}) {
  const [address,setAddress]=useState<Address>({...emptyAddress,name:data.session?.name??'',email:data.session?.email??''});
  const [options,setOptions]=useState<ShippingOption[]>([pickup]);
  const [shippingId,setShippingId]=useState('pickup');
  const [quoting,setQuoting]=useState(false);
  const [submitting,setSubmitting]=useState(false);
  const [message,setMessage]=useState('');
  const [error,setError]=useState('');
  const ref=useDialog(true,()=>{if(!submitting) onClose();});
  const lines=cart.map(line=>({line,product:data.products.find(product=>product.id===line.productId)})).filter(entry=>entry.product) as {line:CartLine;product:Product}[];
  const subtotal=lines.reduce((sum,{line,product})=>sum+product.price*line.quantity,0);
  const shipping=options.find(option=>option.id===shippingId)??pickup;
  const paymentReady=data.integration.mercadopago&&!data.isDemo;
  function updateAddress(key:keyof Address,value:string) {
    setAddress(current=>({...current,[key]:value}));
    if(['postcode','city','state'].includes(key)) {setOptions([pickup]);setShippingId('pickup');setMessage('');}
  }
  async function quote() {
    if(!address.postcode.trim()||!address.city.trim()||!address.state) {setError('Completá código postal, localidad y provincia para cotizar.');return;}
    setQuoting(true);setError('');setMessage('');
    try {
      const response=await fetch('/api/shipping/quote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:cart,destination:{postcode:address.postcode,city:address.city,state:address.state}})});
      const result=await response.json() as {configured:boolean;options?:ShippingOption[];message?:string;error?:string};
      if(!response.ok) throw new Error(result.error||result.message||'No pudimos consultar los envíos.');
      const unique=(result.options??[]).filter(option=>option.id!=='pickup');
      setOptions([pickup,...unique]);
      setMessage(result.message||(!result.configured?'El servicio de logística todavía no está conectado. Podés elegir retiro a coordinar.':unique.length?'Elegí la opción de envío que prefieras.':'No hay servicios de envío para ese destino. Podés elegir retiro a coordinar.'));
    } catch(caught) {setError(caught instanceof Error?caught.message:'No pudimos cotizar el envío.');}
    finally {setQuoting(false);}
  }
  async function submit(event:FormEvent<HTMLFormElement>) {
    event.preventDefault();setSubmitting(true);setError('');
    try {
      const response=await fetch('/api/checkout',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:cart,address,shippingOptionId:shippingId,demo:!paymentReady})});
      const result=await response.json() as {order?:Order;checkoutUrl?:string;message?:string;error?:string};
      if(!response.ok||!result.order) throw new Error(result.error||result.message||'No pudimos crear tu pedido.');
      onComplete(result.order);
      if(result.checkoutUrl) window.location.href=result.checkoutUrl;
    } catch(caught) {setError(caught instanceof Error?caught.message:'No pudimos crear tu pedido.');setSubmitting(false);}
  }
  if(!data.session) return <div className="st-overlay"><div ref={ref} role="dialog" aria-modal="true" aria-labelledby="st-checkout-title" className="st-dialog st-order-dialog"><button type="button" className="st-close" onClick={onClose} aria-label="Cerrar compra"><X size={23}/></button><span className="st-order-success"><UserRound size={38}/></span><h2 id="st-checkout-title">Ingresá para continuar</h2><p className="muted">Guardamos tu carrito en este dispositivo. Con tu cuenta podés comprar y seguir el estado de tus pedidos.</p><a className="button st-pay-button" href="/signin-with-chatgpt?return_to=/" target="_top">Ingresar con ChatGPT <ArrowRight size={17}/></a></div></div>;
  return <div className="st-overlay"><div ref={ref} role="dialog" aria-modal="true" aria-labelledby="st-checkout-title" className="st-dialog st-checkout-dialog"><button className="st-close" type="button" onClick={onClose} disabled={submitting} aria-label="Cerrar compra"><X size={23}/></button><p className="eyebrow">YA CASI ES TUYO</p><h2 id="st-checkout-title">Finalizar compra</h2><p className="muted st-checkout-intro">Completá tus datos y elegí cómo recibir tu pedido.</p><form onSubmit={submit}><div className="st-checkout-grid"><div className="st-checkout-fields"><h3><UserRound size={19}/> Tus datos</h3><div className="st-form-grid"><label className="field">Nombre y apellido<input autoComplete="name" required value={address.name} onChange={event=>updateAddress('name',event.target.value)}/></label><label className="field">Email<input type="email" autoComplete="email" required value={address.email} onChange={event=>updateAddress('email',event.target.value)}/></label><label className="field">Teléfono<input type="tel" autoComplete="tel" required value={address.phone} onChange={event=>updateAddress('phone',event.target.value)}/></label><label className="field">DNI / CUIT<input inputMode="numeric" required value={address.document} onChange={event=>updateAddress('document',event.target.value)}/></label></div><h3><MapPin size={19}/> Dirección</h3><div className="st-form-grid"><label className="field st-field-wide">Calle<input autoComplete="address-line1" required value={address.street} onChange={event=>updateAddress('street',event.target.value)}/></label><label className="field">Número<input required value={address.number} onChange={event=>updateAddress('number',event.target.value)}/></label><label className="field">Piso / departamento (opcional)<input autoComplete="address-line2" value={address.floor} onChange={event=>updateAddress('floor',event.target.value)}/></label><label className="field">Código postal<input autoComplete="postal-code" required value={address.postcode} onChange={event=>updateAddress('postcode',event.target.value)}/></label><label className="field">Localidad<input autoComplete="address-level2" required value={address.city} onChange={event=>updateAddress('city',event.target.value)}/></label><label className="field st-field-wide">Provincia<select autoComplete="address-level1" required value={address.state} onChange={event=>updateAddress('state',event.target.value)}><option value="">Seleccioná una provincia</option>{provinces.map(province=><option key={province}>{province}</option>)}</select></label></div><div className="st-shipping-heading"><h3><Truck size={19}/> Entrega</h3><button type="button" className="button secondary st-quote-button" onClick={quote} disabled={quoting||submitting}>{quoting?<LoaderCircle size={16} className="st-spin"/>:<MapPin size={15}/>} Cotizar envío</button></div>{!data.integration.zipnova&&<p className="st-connection-note">La logística está pendiente de conexión. El retiro a coordinar está disponible.</p>}<div className="st-shipping-options">{options.map(option=><label key={option.id} className={`st-shipping-option ${shippingId===option.id?'is-selected':''}`}><input type="radio" name="shipping" value={option.id} checked={shippingId===option.id} onChange={()=>setShippingId(option.id)}/><span><strong>{option.carrier} · {option.service}</strong><small>{option.estimatedDelivery}</small></span><b>{option.price===0?'Sin cargo':ars(option.price)}</b></label>)}</div>{message&&<p className="st-inline-message" role="status">{message}</p>}</div><aside className="st-checkout-summary"><h3>Tu pedido</h3>{lines.map(({line,product})=><div className="st-checkout-line" key={product.id}><div className="st-checkout-photo"><ProductPhoto product={product}/></div><span>{product.name}<small>{line.quantity} × {ars(product.price)}</small></span></div>)}<div className="st-total-row"><span>Productos</span><b>{ars(subtotal)}</b></div><div className="st-total-row"><span>Entrega</span><b>{shipping.price===0?'Sin cargo':ars(shipping.price)}</b></div><div className="st-total-row st-total"><span>Total estimado</span><b>{ars(subtotal+shipping.price)}</b></div><p className="st-price-note">El total y el stock se verifican al confirmar el pedido.</p><div className={`st-payment-note ${paymentReady?'is-ready':''}`}><CreditCard size={22}/><span><strong>{paymentReady?'Pagá de forma segura con Mercado Pago':'Mercado Pago pendiente de conexión'}</strong><small>{paymentReady?'Te llevamos a Mercado Pago para completar el pago.':'Este pedido es de prueba. No cobra ni reserva productos.'}</small></span></div>{error&&<p className="st-error" role="alert">{error}</p>}<button className="button st-pay-button" type="submit" disabled={submitting||quoting}>{submitting?<LoaderCircle size={18} className="st-spin"/>:paymentReady?<CreditCard size={18}/>:<ShoppingBag size={18}/>} {submitting?'Preparando pedido…':paymentReady?'Pagar con Mercado Pago':'Crear pedido de prueba'}<ArrowRight size={17}/></button><p className="st-checkout-account"><a href="/cuenta">Mi cuenta</a> · Consultá tus pedidos y su estado.</p></aside></div></form></div></div>;
}

export default function Storefront() {
  const [data,setData]=useState<StoreData|null>(null);
  const [loading,setLoading]=useState(true);
  const [fetchError,setFetchError]=useState('');
  const [search,setSearch]=useState('');
  const [categoryId,setCategoryId]=useState('all');
  const [sort,setSort]=useState('featured');
  const [cart,setCart]=useState<CartLine[]>([]);
  const [cartLoaded,setCartLoaded]=useState(false);
  const [detail,setDetail]=useState<Product|null>(null);
  const [checkout,setCheckout]=useState(false);
  const [createdOrder,setCreatedOrder]=useState<Order|null>(null);
  const [toast,setToast]=useState('');
  const toastTimer=useRef<ReturnType<typeof setTimeout>|null>(null);
  const orderDialog=useDialog(Boolean(createdOrder),()=>setCreatedOrder(null));
  useEffect(()=>{
    const controller=new AbortController();
    fetch('/api/store',{signal:controller.signal}).then(async response=>{const result=await response.json() as StoreData & {error?:string};if(!response.ok)throw new Error(result.error||'No pudimos cargar los productos.');setData(result);}).catch(error=>{if(error.name!=='AbortError')setFetchError(error.message);}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});
    queueMicrotask(()=>{
      if(controller.signal.aborted) return;
      try {const saved=JSON.parse(localStorage.getItem('chiqui-cart')??'[]') as unknown;if(Array.isArray(saved))setCart(saved.filter((line):line is CartLine=>Boolean(line&&typeof line==='object'&&typeof line.productId==='string'&&Number.isInteger(line.quantity)&&line.quantity>0)));} catch {try{localStorage.removeItem('chiqui-cart');}catch{}}
      setCartLoaded(true);setSearch(new URLSearchParams(window.location.search).get('q')??'');
    });
    return ()=>{controller.abort();if(toastTimer.current)clearTimeout(toastTimer.current);};
  },[]);
  useEffect(()=>{if(cartLoaded)try{localStorage.setItem('chiqui-cart',JSON.stringify(cart));}catch{}},[cart,cartLoaded]);
  const activeProducts=useMemo(()=>data?.products.filter(product=>product.active)??[],[data]);
  const products=useMemo(()=>{
    const categorySet=categoryId==='all'?null:categoryDescendants(data?.categories??[],categoryId);
    const query=search.trim().toLocaleLowerCase('es');
    const filtered=activeProducts.filter(product=>(!categorySet||categorySet.has(product.categoryId))&&(!query||`${product.name} ${product.brand} ${product.sku} ${categoryPath(data?.categories??[],product.categoryId)}`.toLocaleLowerCase('es').includes(query)));
    return [...filtered].sort((a,b)=>sort==='price-asc'?a.price-b.price:sort==='price-desc'?b.price-a.price:sort==='name'?a.name.localeCompare(b.name,'es'):Number(b.featured)-Number(a.featured));
  },[activeProducts,categoryId,data,search,sort]);
  const cartEntries=cart.map(line=>({line,product:activeProducts.find(product=>product.id===line.productId)}));
  const validEntries=cartEntries.filter((entry):entry is {line:CartLine;product:Product}=>Boolean(entry.product));
  const invalidLines=cartEntries.filter(entry=>!entry.product);
  const cartSubtotal=validEntries.reduce((sum,{line,product})=>sum+line.quantity*product.price,0);
  const cartCount=cart.reduce((sum,line)=>sum+line.quantity,0);
  const roots=data?.categories.filter(category=>!category.parentId)??[];
  function announce(message:string) {setToast(message);if(toastTimer.current)clearTimeout(toastTimer.current);toastTimer.current=setTimeout(()=>setToast(''),4000);}
  function addToCart(product:Product,quantity=1) {
    if(!product.stock) return;
    const current=cart.find(line=>line.productId===product.id)?.quantity??0;
    const next=Math.min(product.stock,current+quantity);
    if(next===current) {announce('Ya agregaste todas las unidades disponibles de este producto.');return;}
    setCart(lines=>lines.some(line=>line.productId===product.id)?lines.map(line=>line.productId===product.id?{...line,quantity:next}:line):[...lines,{productId:product.id,quantity:next}]);announce(`${product.name} agregado al carrito`);
  }
  function updateQuantity(product:Product,quantity:number) {setCart(lines=>quantity<=0?lines.filter(line=>line.productId!==product.id):lines.map(line=>line.productId===product.id?{...line,quantity:Math.min(product.stock,quantity)}:line));}
  function filterCategory(id:string) {setCategoryId(id);document.getElementById('productos')?.scrollIntoView({behavior:'smooth',block:'start'});}
  function complete(order:Order) {setCart([]);setCheckout(false);setCreatedOrder(order);}
  return <><StoreHeader search={search} onSearch={setSearch} cartCount={cartCount}/><main>
    <section className="container st-hero" aria-label="Novedades de la tienda"><div className="st-hero-main"><div className="st-hero-copy"><span className="st-hero-eyebrow"><span/> RENOVÁ TU SETUP</span><h1>Más tecnología.<br/><strong>Más posibilidades.</strong></h1><p>Todo para tu PC, tu trabajo<br/>y tus mejores partidas.</p><a href="#productos" className="st-hero-button">Encontrá lo que necesitás <ArrowRight size={17}/></a><span className="st-hero-footnote">COMPONENTES · ACCESORIOS · PERIFÉRICOS</span></div><div className="st-hero-art" aria-hidden="true"><div className="st-art-orbit st-orbit-one"/><div className="st-art-orbit st-orbit-two"/><div className="st-art-monitor"><div className="st-art-screen"><span className="st-screen-line"/><span className="st-screen-brand">C<span>+</span></span><span className="st-screen-caption">MAKE IT HAPPEN.</span></div><div className="st-monitor-neck"/><div className="st-monitor-foot"/></div><div className="st-art-keyboard">{Array.from({length:42},(_,index)=><i key={index}/>)}</div><div className="st-art-mouse"/><div className="st-art-tag"><Cpu size={18}/><span>Tu próximo nivel.</span></div></div><div className="st-hero-dots" aria-hidden="true"><i className="is-current"/><i/><i/></div></div><div className="st-hero-side"><a href="#productos" className="st-mini-banner st-banner-gaming"><span className="st-banner-copy"><small>TODO PARA JUGAR</small><strong>Tu equipo.<br/>Tus reglas.</strong><span>Explorá el catálogo <ArrowRight size={14}/></span></span><Headphones size={122} strokeWidth={1.4} className="st-banner-icon"/></a><a href="#categorias" className="st-mini-banner st-banner-work"><span className="st-banner-copy"><small>HACÉ MÁS, TODOS LOS DÍAS</small><strong>Conectá con<br/>lo que importa.</strong><span>Elegí tus accesorios <ArrowRight size={14}/></span></span><Keyboard size={106} strokeWidth={1.3} className="st-banner-icon"/></a></div></section>
    <section className="container st-trust" aria-label="Beneficios"><div><Truck size={29}/><span><strong>Envíos a todo el país</strong><small>Cotizá con tu código postal</small></span></div><div><CreditCard size={29}/><span><strong>Mercado Pago</strong><small>Opciones de pago al finalizar</small></span></div><div><ShieldCheck size={29}/><span><strong>Comprá con confianza</strong><small>Productos y stock actualizados</small></span></div><div><Package size={29}/><span><strong>Tus compras, a un clic</strong><small>Seguí cada pedido en tu cuenta</small></span></div></section>
    <section className="container st-category-section" id="categorias"><div className="st-section-heading"><div><p className="eyebrow">ARMÁ TU MUNDO</p><h2>Encontrá tu próxima mejora</h2></div><button type="button" className="st-text-link" onClick={()=>filterCategory('all')}>Ver todas las categorías <ArrowRight size={16}/></button></div><div className="st-category-tiles">{roots.length?roots.map((category,index)=>{const icons=[Monitor,Cpu,Keyboard,Headphones,Mouse,Package];const Icon=icons[index%icons.length];return <button type="button" key={category.id} onClick={()=>filterCategory(category.id)} className={categoryId===category.id?'is-selected':''}><span className="st-category-icon"><Icon size={34} strokeWidth={1.5}/></span><strong>{category.name}</strong><ChevronRight size={16}/></button>;}):[Monitor,Cpu,Keyboard,Headphones].map((Icon,index)=><div className="st-category-skeleton" key={index}><Icon size={34}/><span>{loading?'Cargando…':'Categorías'}</span></div>)}</div></section>
    <section className="container st-products-section" id="productos"><div className="st-section-heading" id="destacados"><div><p className="eyebrow">TECNOLOGÍA QUE VA CON VOS</p><h2>{search?`Resultados para “${search}”`:categoryId!=='all'?data?.categories.find(category=>category.id===categoryId)?.name:'Productos destacados'}</h2></div><span className="st-product-count">{products.length} productos</span></div><div className="st-catalog-toolbar"><div className="st-category-filter"><SlidersHorizontal size={17}/><label htmlFor="st-category-select">Categoría</label><select id="st-category-select" value={categoryId} onChange={event=>setCategoryId(event.target.value)}><option value="all">Todas las categorías</option>{data?.categories.map(category=><option key={category.id} value={category.id}>{categoryPath(data.categories,category.id)}</option>)}</select></div><label className="st-sort">Ordenar por <select value={sort} onChange={event=>setSort(event.target.value)}><option value="featured">Destacados</option><option value="price-asc">Menor precio</option><option value="price-desc">Mayor precio</option><option value="name">Nombre</option></select></label></div>
    {loading?<div className="st-product-grid" aria-label="Cargando productos">{Array.from({length:4},(_,index)=><div className="st-product-skeleton" key={index}><div/><span/><span/><span/></div>)}</div>:fetchError?<div className="empty st-catalog-empty"><Package size={40}/><h3>No pudimos cargar el catálogo</h3><p>{fetchError}</p><button type="button" className="button" onClick={()=>window.location.reload()}>Reintentar</button></div>:!products.length?<div className="empty st-catalog-empty"><Search size={40}/><h3>No encontramos productos</h3><p>Probá con otro nombre o seleccioná otra categoría.</p><button className="button secondary" type="button" onClick={()=>{setSearch('');setCategoryId('all');}}>Ver todos los productos</button></div>:<div className="st-product-grid">{products.map(product=><article className="st-product-card" key={product.id}><button type="button" className="st-product-image" onClick={()=>setDetail(product)} aria-label={`Ver ${product.name}`}><ProductPhoto product={product}/>{product.featured&&<span className="st-featured-label">DESTACADO</span>}{!product.stock&&<span className="st-no-stock-label">SIN STOCK</span>}</button><div className="st-product-body"><span className="st-product-brand">{product.brand}</span><button type="button" className="st-product-title" onClick={()=>setDetail(product)}>{product.name}</button><span className={`st-stock ${product.stock?'':'unavailable'}`}><span/>{product.stock?'En stock':'Sin stock'}</span><p className="st-product-price">{ars(product.price)}</p><span className="st-product-price-note">Precio final</span><button type="button" className="st-add-button" onClick={()=>addToCart(product)} disabled={!product.stock}><ShoppingBag size={16}/>{product.stock?'Agregar al carrito':'No disponible'}<Plus size={15}/></button></div></article>)}</div>}
    </section>
    <section className="container st-cart-section" id="carrito"><div className="st-section-heading"><div><p className="eyebrow">TU PRÓXIMA COMPRA</p><h2>Mi carrito <span className="st-cart-title-count">{cartCount}</span></h2></div><a className="st-text-link" href="/cuenta">Ver mis pedidos <ArrowRight size={16}/></a></div>{cart.length?<div className="st-cart-grid"><div className="st-cart-items">{validEntries.map(({line,product})=><article className="st-cart-item" key={product.id}><button type="button" className="st-cart-photo" onClick={()=>setDetail(product)} aria-label={`Ver ${product.name}`}><ProductPhoto product={product}/></button><div className="st-cart-item-info"><span className="st-product-brand">{product.brand}</span><h3>{product.name}</h3><span>{ars(product.price)} por unidad</span>{line.quantity>product.stock&&<p className="st-error">Stock disponible: {product.stock}. Ajustá la cantidad.</p>}</div><div className="st-quantity"><button type="button" aria-label={`Restar ${product.name}`} onClick={()=>updateQuantity(product,line.quantity-1)}><Minus size={14}/></button><output aria-label="Cantidad">{line.quantity}</output><button type="button" aria-label={`Sumar ${product.name}`} disabled={line.quantity>=product.stock} onClick={()=>updateQuantity(product,line.quantity+1)}><Plus size={14}/></button></div><strong className="st-cart-line-total">{ars(product.price*line.quantity)}</strong><button type="button" className="st-remove-button" aria-label={`Quitar ${product.name}`} onClick={()=>setCart(lines=>lines.filter(entry=>entry.productId!==product.id))}><Trash2 size={17}/></button></article>)}{invalidLines.map(({line})=><div key={line.productId} className="st-cart-item st-invalid-cart"><p>Este producto ya no está disponible.</p><button type="button" className="button secondary" onClick={()=>setCart(lines=>lines.filter(entry=>entry.productId!==line.productId))}>Quitar del carrito</button></div>)}</div><aside className="st-cart-summary"><h3>Resumen de compra</h3><div className="st-total-row"><span>Productos ({cartCount})</span><strong>{ars(cartSubtotal)}</strong></div><div className="st-total-row"><span>Envío</span><span>A cotizar</span></div><div className="st-total-row st-total"><span>Subtotal</span><strong>{ars(cartSubtotal)}</strong></div><button type="button" className="button" onClick={()=>setCheckout(true)} disabled={!validEntries.length||Boolean(invalidLines.length)||validEntries.some(({line,product})=>line.quantity>product.stock)}>Continuar compra <ArrowRight size={17}/></button><span className="st-summary-security"><ShieldCheck size={15}/> Confirmá entrega y pago en el siguiente paso.</span></aside></div>:<div className="st-empty-cart"><span><ShoppingBag size={29}/></span><div><h3>Tu carrito está esperando una buena idea</h3><p>Elegí tus productos y encontralos acá.</p></div><a className="button secondary" href="#productos">Explorar productos <ArrowRight size={16}/></a></div>}</section>
    <section className="st-bottom-banner"><div className="container"><div><Headphones size={39}/><span><strong>La tecnología se disfruta más cuando todo funciona.</strong><p>Encontrá tus productos, comprá y seguí cada pedido desde tu cuenta.</p></span></div><a href="/cuenta" className="button secondary">Ingresar a mi cuenta <ArrowRight size={17}/></a></div></section>
  </main><footer className="st-footer"><div className="container st-footer-main"><Link href="/" className="st-brand"><span className="st-brand-mark">C<span>+</span></span><span>CHIQUI<small>COMPUTACIÓN & TECNOLOGÍA</small></span></Link><div><strong>La tienda</strong><a href="#productos">Productos</a><a href="#categorias">Categorías</a></div><div><strong>Tu cuenta</strong><a href="/cuenta">Mis compras</a><a href="#carrito">Mi carrito</a></div><div><strong>Entrega y pago</strong><span>Envíos sujetos a cotización</span><span>Mercado Pago</span></div></div><div className="container st-footer-bottom"><span>© {new Date().getFullYear()} Chiqui. Todos los derechos reservados.</span><span>Los precios se expresan en pesos argentinos.</span></div></footer>
  {toast&&<div className="st-toast" role="status"><Check size={19}/><span>{toast}</span><a href="#carrito" onClick={()=>setToast('')}>Ver carrito</a><button aria-label="Cerrar aviso" onClick={()=>setToast('')}><X size={16}/></button></div>}
  {detail&&data&&<ProductDetail key={detail.id} product={detail} categories={data.categories} onClose={()=>setDetail(null)} onAdd={addToCart}/>}
  {checkout&&data&&<Checkout data={data} cart={cart} onClose={()=>setCheckout(false)} onComplete={complete}/>}
  {createdOrder&&<div className="st-overlay"><div ref={orderDialog} role="dialog" aria-modal="true" aria-labelledby="st-order-title" className="st-dialog st-order-dialog"><button type="button" className="st-close" aria-label="Cerrar confirmación" onClick={()=>setCreatedOrder(null)}><X size={23}/></button><span className="st-order-success"><CircleCheck size={40}/></span><p className="eyebrow">{createdOrder.isDemo?'PEDIDO DE PRUEBA':'PEDIDO CREADO'}</p><h2 id="st-order-title">{createdOrder.isDemo?'Tu pedido de prueba está listo':'Recibimos tu pedido'}</h2><p>Pedido <strong>{createdOrder.number}</strong> · {ars(createdOrder.total)}</p><p className="muted">{createdOrder.isDemo?'Es una simulación: no se realizó ningún cobro ni se reservó stock.':'Podés ver el estado de pago y entrega desde tu cuenta.'}</p><div className="st-order-actions"><a href="/cuenta" className="button">Ver mis compras <ArrowRight size={16}/></a><button type="button" className="button secondary" onClick={()=>setCreatedOrder(null)}>Seguir explorando</button></div></div></div>}
  </>;
}
