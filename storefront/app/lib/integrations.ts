import type { CartLine, Order, Product, ShippingOption } from './domain';

export type CommerceEnv = {
  MERCADOPAGO_ACCESS_TOKEN?: string;
  MERCADOPAGO_WEBHOOK_SECRET?: string;
  ZIPNOVA_API_TOKEN?: string;
  ZIPNOVA_API_SECRET?: string;
  ZIPNOVA_ACCOUNT_ID?: string;
  ZIPNOVA_ORIGIN_ID?: string;
  ZIPNOVA_ORIGIN_POSTCODE?: string;
};
export type ProviderQuote = ShippingOption & { selection: Record<string, unknown> };
export type PaymentInfo = {
  id: string;
  externalReference: string;
  currency: string;
  total: number;
  status: string;
};

type JsonObject = Record<string, unknown>;
type ShippingItem = { weight: number; height: number; width: number; length: number; description: string };
const MP_API = 'https://api.mercadopago.com';
const ZIPNOVA_API = 'https://api.zipnova.com.ar/v2';
const MAX_UNITS = 1000;
const MAX_LINE_QUANTITY = 100;
const MAX_MONEY_CENTS = 100_000_000_000;
const encoder = new TextEncoder();

function object(value: unknown): JsonObject {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as JsonObject : {};
}
function integer(value: unknown, min: number, max: number, message: string): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < min || value > max) throw new Error(message);
  return value;
}
function textValue(value: unknown, max: number, message: string): string {
  if (typeof value !== 'string' || !value.trim() || value.trim().length > max || /[\u0000-\u001f\u007f]/.test(value)) throw new Error(message);
  return value.trim();
}
function cents(value: unknown, allowZero = false): number {
  const amount = typeof value === 'string' && /^\d+(?:\.\d{1,2})?$/.test(value) ? Number(value) : value;
  if (typeof amount !== 'number' || !Number.isFinite(amount)) throw new Error('El importe no es válido.');
  const result = Math.round(amount * 100);
  if (!Number.isSafeInteger(result) || result < (allowZero ? 0 : 1) || result > MAX_MONEY_CENTS || Math.abs(amount * 100 - result) > 0.0001) throw new Error('El importe no es válido.');
  return result;
}
function accountNumber(value: string | undefined, label: string): number {
  if (!value || !/^\d{1,15}$/.test(value)) throw new Error(`Configurá ${label} de Zipnova.`);
  return integer(Number(value), 1, Number.MAX_SAFE_INTEGER, `Configurá ${label} de Zipnova.`);
}
function requiredSecret(value: string | undefined, provider: string): string {
  if (!value || value.length > 4096 || /[\r\n]/.test(value)) throw new Error(`Falta configurar la conexión con ${provider}.`);
  return value;
}
function providerDimension(value: unknown, label: string): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0.1 || value > 5000) throw new Error(`El ${label} debe estar entre 0,1 y 5000 centímetros.`);
  // Zipnova receives whole centimeters; keep the catalog's original measures.
  return Math.ceil(value);
}
function shippingItem(value: unknown): ShippingItem {
  const item = object(value);
  return {
    weight: Math.max(10, integer(item.weight, 1, 10_000_000, 'El peso debe estar expresado en gramos enteros, desde 1 gramo.')),
    height: providerDimension(item.height, 'alto'),
    width: providerDimension(item.width, 'ancho'),
    length: providerDimension(item.length, 'largo'),
    description: textValue(item.description, 200, 'El producto necesita un nombre válido.'),
  };
}
function zipHeaders(env: CommerceEnv): HeadersInit {
  const token = requiredSecret(env.ZIPNOVA_API_TOKEN, 'Zipnova');
  const secret = requiredSecret(env.ZIPNOVA_API_SECRET, 'Zipnova');
  const bytes = encoder.encode(`${token}:${secret}`);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return { Authorization: `Basic ${btoa(binary)}`, Accept: 'application/json', 'Content-Type': 'application/json' };
}
function mpHeaders(env: CommerceEnv): HeadersInit {
  return { Authorization: `Bearer ${requiredSecret(env.MERCADOPAGO_ACCESS_TOKEN, 'Mercado Pago')}`, Accept: 'application/json', 'Content-Type': 'application/json' };
}
async function providerRequest(url: string, headers: HeadersInit, provider: string, body?: JsonObject): Promise<JsonObject> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: body ? 'POST' : 'GET', headers, body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(15_000), redirect: 'error', cache: 'no-store', credentials: 'omit',
    });
  } catch {
    throw new Error(`No pudimos comunicarnos con ${provider}. Intentá nuevamente.`);
  }
  if (!response.ok) {
    if (response.status === 401 || response.status === 403) throw new Error(`Revisá las credenciales y permisos de ${provider}.`);
    if (response.status === 429) throw new Error(`${provider} está ocupado. Intentá nuevamente en unos minutos.`);
    if (response.status === 400 || response.status === 422) throw new Error(`${provider} no pudo procesar los datos. Revisá el pedido y la dirección.`);
    throw new Error(`${provider} no pudo completar la operación. Intentá nuevamente.`);
  }
  try {
    const raw = await response.text();
    if (raw.length > 2_000_000) throw new Error();
    const data = JSON.parse(raw) as unknown;
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error();
    return data as JsonObject;
  } catch {
    throw new Error(`Recibimos una respuesta inválida de ${provider}. Intentá nuevamente.`);
  }
}
function httpsUrl(value: unknown): string | null {
  if (typeof value !== 'string' || value.length > 2048) return null;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && !url.username && !url.password ? url.toString() : null;
  } catch { return null; }
}
function destinationValue(destination: { postcode: string; city: string; state: string }): JsonObject {
  return {
    zipcode: textValue(destination.postcode, 20, 'Ingresá un código postal válido.'),
    city: textValue(destination.city, 100, 'Ingresá la ciudad de destino.'),
    state: textValue(destination.state, 100, 'Ingresá la provincia de destino.'),
  };
}

export async function quoteShipping(env: CommerceEnv, products: Product[], items: CartLine[], destination: { postcode: string; city: string; state: string }): Promise<ProviderQuote[]> {
  if (!Array.isArray(items) || items.length === 0 || items.length > MAX_UNITS) throw new Error('El carrito necesita productos para cotizar el envío.');
  const productMap = new Map(products.map(product => [product.id, product]));
  const units: ShippingItem[] = [];
  const seen = new Set<string>();
  let declaredCents = 0;
  for (const line of items) {
    const quantity = integer(line.quantity, 1, MAX_LINE_QUANTITY, 'La cantidad de un producto debe estar entre 1 y 100.');
    if (seen.has(line.productId)) throw new Error('Agrupá las cantidades de cada producto en una sola línea.');
    seen.add(line.productId);
    const product = productMap.get(line.productId);
    if (!product || !product.active || product.stock < quantity) throw new Error('Un producto del carrito no está disponible.');
    const unit = shippingItem({ weight: product.weightGrams, height: product.heightCm, width: product.widthCm, length: product.lengthCm, description: product.name });
    if (units.length + quantity > MAX_UNITS) throw new Error('El envío admite hasta 1000 unidades.');
    for (let index = 0; index < quantity; index++) units.push({ ...unit });
    declaredCents += cents(product.price) * quantity;
    integer(declaredCents, 1, MAX_MONEY_CENTS, 'El valor declarado del envío no es válido.');
  }
  const body: JsonObject = {
    account_id: accountNumber(env.ZIPNOVA_ACCOUNT_ID, 'el ID de cuenta'),
    source: 'api', declared_value: declaredCents / 100,
    destination: destinationValue(destination), items: units, type_packaging: 'none', sort_by: 'price',
  };
  if (env.ZIPNOVA_ORIGIN_ID) body.origin_id = accountNumber(env.ZIPNOVA_ORIGIN_ID, 'el ID de origen');
  const data = await providerRequest(`${ZIPNOVA_API}/shipments/quote`, zipHeaders(env), 'Zipnova', body);
  const resolvedOrigin = object(data.origin);
  const results = Array.isArray(data.all_results) ? data.all_results : Object.values(object(data.results));
  const quotes: ProviderQuote[] = [];
  for (const [index, raw] of results.slice(0, 200).entries()) {
    const result = object(raw);
    if (result.selectable !== true) continue;
    try {
      const carrier = object(result.carrier);
      const service = object(result.service_type);
      const carrierId = integer(carrier.id, 1, Number.MAX_SAFE_INTEGER, 'Transportista inválido.');
      const logisticType = textValue(result.logistic_type, 100, 'Tipo de despacho inválido.');
      const serviceCode = textValue(service.code, 100, 'Servicio inválido.');
      const carrierName = textValue(carrier.name, 100, 'Transportista inválido.');
      const serviceName = textValue(service.name || serviceCode, 100, 'Servicio inválido.');
      const price = cents(object(result.amounts).price_incl_tax, true) / 100;
      const estimated = object(result.delivery_time).estimated_delivery;
      const estimatedDelivery = typeof estimated === 'string' && estimated.length <= 100 ? estimated : '';
      const selection: JsonObject = {
        ...body, carrier_id: carrierId, logistic_type: logisticType, service_type: serviceCode,
        quote_price: price, origin_postcode: env.ZIPNOVA_ORIGIN_POSTCODE || resolvedOrigin.zipcode || '',
      };
      if (serviceCode === 'pickup_point') {
        const points = Array.isArray(result.pickup_points) ? result.pickup_points.slice(0, 5) : [];
        for (const pointRaw of points) {
          const point = object(pointRaw);
          const pointId = integer(point.point_id, 1, Number.MAX_SAFE_INTEGER, 'Punto de retiro inválido.');
          const name = textValue(point.description, 160, 'Punto de retiro inválido.');
          quotes.push({ id: `zipnova-${carrierId}-${index}-${pointId}`, carrier: carrierName, service: `${serviceName}: ${name}`, price, estimatedDelivery, selection: { ...selection, point_id: pointId } });
        }
      } else {
        quotes.push({ id: `zipnova-${carrierId}-${index}`, carrier: carrierName, service: serviceName, price, estimatedDelivery, selection });
      }
    } catch {
      // An incomplete carrier response must not become a purchasable quote.
    }
  }
  if (!quotes.length) throw new Error('Zipnova no tiene opciones disponibles para esta dirección y estos productos.');
  return quotes.sort((left, right) => left.price - right.price);
}

function orderAmounts(order: Order): { items: JsonObject[]; subtotalCents: number; shippingCents: number; totalCents: number; units: number } {
  if (!Array.isArray(order.items) || !order.items.length || order.items.length > MAX_UNITS) throw new Error('El pedido necesita productos válidos.');
  let subtotalCents = 0;
  let units = 0;
  const items = order.items.map(item => {
    const quantity = integer(item.quantity, 1, MAX_LINE_QUANTITY, 'La cantidad de un producto debe estar entre 1 y 100.');
    const unitPriceCents = cents(item.unitPrice);
    units += quantity;
    if (units > MAX_UNITS) throw new Error('El pedido admite hasta 1000 unidades.');
    subtotalCents += unitPriceCents * quantity;
    integer(subtotalCents, 1, MAX_MONEY_CENTS, 'El total del pedido no es válido.');
    return { id: textValue(item.productId, 200, 'El producto necesita un identificador.'), title: textValue(item.name, 200, 'El producto necesita un nombre.'), quantity, currency_id: 'ARS', unit_price: unitPriceCents / 100 };
  });
  const shippingCents = cents(order.shippingCost, true);
  const totalCents = cents(order.total);
  if (subtotalCents + shippingCents !== totalCents) throw new Error('El total del pedido no coincide con sus productos y el envío.');
  return { items, subtotalCents, shippingCents, totalCents, units };
}

export async function createPayment(env: CommerceEnv, order: Order, origin: string): Promise<{ id: string; url: string }> {
  const amounts = orderAmounts(order);
  const orderId = textValue(order.id, 200, 'El pedido necesita un identificador válido.');
  let base: URL;
  try { base = new URL(origin); } catch { throw new Error('La dirección de la tienda no es válida.'); }
  if (base.protocol !== 'https:' || base.username || base.password) throw new Error('La tienda necesita una dirección HTTPS para cobrar con Mercado Pago.');
  const body: JsonObject = {
    items: amounts.items,
    payer: { email: textValue(order.customerEmail, 254, 'El pedido necesita un correo válido.') },
    external_reference: orderId,
    shipments: { mode: 'not_specified', cost: amounts.shippingCents / 100, free_shipping: amounts.shippingCents === 0 },
    notification_url: new URL('/api/payments/webhook', base.origin).toString(),
    back_urls: {
      success: new URL('/cuenta?payment=success', base.origin).toString(),
      pending: new URL('/cuenta?payment=pending', base.origin).toString(),
      failure: new URL('/cuenta?payment=failure', base.origin).toString(),
    },
    auto_return: 'approved',
  };
  const data = await providerRequest(`${MP_API}/checkout/preferences`, mpHeaders(env), 'Mercado Pago', body);
  const id = textValue(data.id, 200, 'Mercado Pago no devolvió un identificador de pago.');
  const checkout = env.MERCADOPAGO_ACCESS_TOKEN?.startsWith('TEST-') ? data.sandbox_init_point : data.init_point;
  const url = httpsUrl(checkout);
  if (!url || !/(^|\.)mercadopago\.com(?:\.ar)?$/.test(new URL(url).hostname)) throw new Error('Mercado Pago no devolvió una dirección de pago válida.');
  return { id, url };
}

export async function validateMPWebhook(request: Request, secret: string): Promise<string | null> {
  try {
    if (!secret || secret.length > 4096) return null;
    const values = new URL(request.url).searchParams.getAll('data.id');
    if (values.length !== 1 || !/^[A-Za-z0-9_-]{1,128}$/.test(values[0])) return null;
    const dataId = values[0];
    const requestId = request.headers.get('x-request-id');
    const signature = request.headers.get('x-signature');
    if (!requestId || requestId.length > 200 || /[\u0000-\u001f\u007f;]/.test(requestId) || !signature || signature.length > 1024) return null;
    const parts = new Map<string, string>();
    for (const part of signature.split(',')) {
      const pair = /^\s*([A-Za-z0-9_-]+)\s*=\s*([^\s,]+)\s*$/.exec(part);
      if (!pair || parts.has(pair[1])) return null;
      parts.set(pair[1], pair[2]);
    }
    const ts = parts.get('ts');
    const hash = parts.get('v1');
    if (!ts || !/^\d{10,13}$/.test(ts) || !hash || !/^[a-fA-F0-9]{64}$/.test(hash)) return null;
    // Provider examples include seconds and milliseconds; preserve the signed value.
    const timestampMs = ts.length === 13 ? Number(ts) : Number(ts) * 1000;
    if (Math.abs(Date.now() - timestampMs) > 300_000) return null;
    const manifest = `id:${dataId.toLowerCase()};request-id:${requestId};ts:${ts};`;
    const bytes = Uint8Array.from(hash.match(/.{2}/g)!, byte => parseInt(byte, 16));
    const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
    // WebCrypto verifies the MAC through the native cryptographic implementation.
    const valid = await crypto.subtle.verify('HMAC', key, bytes, encoder.encode(manifest));
    return valid ? dataId : null;
  } catch { return null; }
}

function paymentInfo(value: unknown): PaymentInfo {
  const payment = object(value);
  const id = String(payment.id ?? '');
  if (!/^\d{1,32}$/.test(id)) throw new Error('Mercado Pago devolvió un pago inválido.');
  return {
    id,
    externalReference: typeof payment.external_reference === 'string' ? payment.external_reference.slice(0, 200) : '',
    currency: textValue(payment.currency_id, 10, 'Mercado Pago devolvió una moneda inválida.'),
    total: cents(payment.transaction_amount) / 100,
    status: textValue(payment.status, 60, 'Mercado Pago devolvió un estado inválido.'),
  };
}
export async function fetchPayment(env: CommerceEnv, id: string): Promise<PaymentInfo> {
  if (!/^\d{1,32}$/.test(id)) throw new Error('El identificador del pago no es válido.');
  const result = paymentInfo(await providerRequest(`${MP_API}/v1/payments/${id}`, mpHeaders(env), 'Mercado Pago'));
  if (result.id !== id) throw new Error('Mercado Pago devolvió otro pago. Intentá nuevamente.');
  return result;
}
export async function findPayments(env: CommerceEnv, orderId: string): Promise<PaymentInfo[]> {
  const reference = textValue(orderId, 200, 'El pedido necesita un identificador válido.');
  const url = new URL(`${MP_API}/v1/payments/search`);
  url.searchParams.set('external_reference', reference);
  url.searchParams.set('limit', '20');
  const data = await providerRequest(url.toString(), mpHeaders(env), 'Mercado Pago');
  if (!Array.isArray(data.results)) throw new Error('Mercado Pago no devolvió una lista de pagos válida.');
  return data.results.slice(0, 20).map(paymentInfo).filter(payment => payment.externalReference === reference);
}

export async function createShipment(env: CommerceEnv, order: Order, selection: Record<string, unknown>): Promise<{ id: string; trackingCode: string | null; trackingUrl: string | null }> {
  const amounts = orderAmounts(order);
  const accountId = accountNumber(env.ZIPNOVA_ACCOUNT_ID, 'el ID de cuenta');
  if (selection.account_id !== accountId || cents(selection.declared_value, true) !== amounts.subtotalCents || cents(selection.quote_price, true) !== amounts.shippingCents) throw new Error('La cotización no corresponde a este pedido. Volvé a cotizar el envío.');
  const quotedDestination = object(selection.destination);
  const destination = destinationValue({ postcode: order.address.postcode, city: order.address.city, state: order.address.state });
  for (const field of ['zipcode', 'city', 'state']) {
    if (String(quotedDestination[field] ?? '').trim().toLocaleLowerCase('es-AR') !== String(destination[field]).trim().toLocaleLowerCase('es-AR')) throw new Error('La dirección cambió desde la cotización. Volvé a cotizar el envío.');
  }
  if (!Array.isArray(selection.items) || selection.items.length !== amounts.units || selection.items.length > MAX_UNITS) throw new Error('La cotización no incluye todos los productos del pedido.');
  const items = selection.items.map(shippingItem);
  const originId = env.ZIPNOVA_ORIGIN_ID ? accountNumber(env.ZIPNOVA_ORIGIN_ID, 'el ID de origen') : integer(selection.origin_id, 1, Number.MAX_SAFE_INTEGER, 'Configurá el origen del envío en Zipnova.');
  if (selection.origin_id !== originId) throw new Error('El origen cambió desde la cotización. Volvé a cotizar el envío.');
  const serviceType = textValue(selection.service_type, 100, 'La cotización necesita un servicio válido.');
  const fullDestination: JsonObject = {
    ...destination,
    name: textValue(order.address.name, 160, 'Ingresá el nombre del destinatario.'),
    document: textValue(order.address.document, 30, 'Ingresá el documento del destinatario.'),
    email: textValue(order.address.email, 254, 'Ingresá el correo del destinatario.'),
    phone: textValue(order.address.phone, 40, 'Ingresá el teléfono del destinatario.'),
  };
  if (serviceType === 'pickup_point') {
    fullDestination.point_id = integer(selection.point_id, 1, Number.MAX_SAFE_INTEGER, 'Elegí un punto de retiro válido.');
    delete fullDestination.city;
    delete fullDestination.state;
  } else {
    fullDestination.street = textValue(order.address.street, 160, 'Ingresá la calle del destinatario.');
    fullDestination.street_number = textValue(order.address.number, 20, 'Ingresá la altura del destinatario.');
    fullDestination.street_extras = order.address.floor ? textValue(order.address.floor, 160, 'Revisá el piso o departamento.') : '';
  }
  const orderId = textValue(order.id, 200, 'El pedido necesita un identificador válido.');
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(orderId)));
  const externalId = `CHI-${Array.from(digest, byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 24)}`;
  const body: JsonObject = {
    account_id: accountId, origin_id: String(originId), external_id: externalId,
    source: 'api', declared_value: amounts.subtotalCents / 100,
    carrier_id: integer(selection.carrier_id, 1, Number.MAX_SAFE_INTEGER, 'Elegí un transportista válido.'),
    logistic_type: textValue(selection.logistic_type, 100, 'Elegí un modo de despacho válido.'),
    service_type: serviceType, destination: fullDestination, items,
    type_packaging: 'none', process_immediately: 0,
  };
  const data = await providerRequest(`${ZIPNOVA_API}/shipments`, zipHeaders(env), 'Zipnova', body);
  const id = typeof data.id === 'number' ? String(integer(data.id, 1, Number.MAX_SAFE_INTEGER, 'Zipnova devolvió un envío inválido.')) : textValue(data.id, 100, 'Zipnova no devolvió un identificador de envío.');
  const code = data.carrier_tracking_id || data.delivery_id;
  return { id, trackingCode: typeof code === 'string' && code.length <= 200 ? code : null, trackingUrl: httpsUrl(data.tracking) || httpsUrl(data.tracking_external) };
}
