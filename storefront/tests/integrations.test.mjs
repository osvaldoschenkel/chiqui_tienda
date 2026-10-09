import assert from 'node:assert/strict';
import test from 'node:test';
import { createHmac } from 'node:crypto';
import {
  quoteShipping, createPayment, validateMPWebhook,
  fetchPayment, findPayments, createShipment,
} from '../app/lib/integrations.ts';

// Run with Node 24: node --test tests/integrations.test.mjs
// Each test replaces fetch; no test contacts payment or logistics services.
const env = {
  MERCADOPAGO_ACCESS_TOKEN: 'test-only-mp-token',
  ZIPNOVA_API_TOKEN: 'test-only-zip-token',
  ZIPNOVA_API_SECRET: 'test-only-zip-secret',
  ZIPNOVA_ACCOUNT_ID: '41',
  ZIPNOVA_ORIGIN_ID: '52',
};
const products = [
  { id: 'notebook', sku: 'NB-01', name: 'Notebook', brand: 'Demo', description: '', price: 100.10, stock: 10, categoryId: 'hardware', active: true, featured: false, weightGrams: 2100, heightCm: 8, widthCm: 35, lengthCm: 45, media: [] },
  { id: 'mouse', sku: 'MS-01', name: 'Mouse', brand: 'Demo', description: '', price: 2.05, stock: 10, categoryId: 'hardware', active: true, featured: false, weightGrams: 175, heightCm: 5, widthCm: 7, lengthCm: 12, media: [] },
];
const cart = [{ productId: 'notebook', quantity: 2 }, { productId: 'mouse', quantity: 1 }];
const address = { name: 'Cliente de prueba', email: 'cliente@example.test', phone: '1112345678', document: '12345678', street: 'Calle de prueba', number: '123', floor: '2 B', city: 'CABA', state: 'CABA', postcode: '1000' };
const order = {
  id: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', number: 'TC-TEST', createdAt: '2026-10-09T12:00:00.000Z',
  status: 'Preparando', paymentStatus: 'Aprobado', total: 214.35, shippingCost: 12.10,
  shippingMethod: 'Correo de prueba', trackingCode: null, trackingUrl: null, isDemo: false,
  items: [{ productId: 'notebook', name: 'Notebook comprado', quantity: 2, unitPrice: 100.10, image: null }, { productId: 'mouse', name: 'Mouse comprado', quantity: 1, unitPrice: 2.05, image: null }],
  address, customerName: address.name, customerEmail: address.email, paymentUrl: null,
};
const quoteResponse = {
  origin: { id: 900, zipcode: '2000' },
  all_results: [
    { selectable: false, carrier: { id: 1, name: 'No disponible' }, service_type: { code: 'standard_delivery', name: 'Domicilio' }, logistic_type: 'carrier_dropoff', amounts: { price_incl_tax: 1 } },
    { selectable: true, carrier: { id: 63, name: 'Correo de prueba' }, service_type: { code: 'standard_delivery', name: 'Domicilio' }, logistic_type: 'carrier_dropoff', amounts: { price: 10, price_incl_tax: 12.10 }, delivery_time: { estimated_delivery: '2026-10-14' } },
  ],
};

function mockFetch(t, handler) {
  const calls = [];
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    const call = { url: String(url), options, body: options?.body ? JSON.parse(options.body) : null };
    calls.push(call);
    return handler(call);
  });
  return calls;
}
function signedRequest({ id = '123', timestamp = String(Math.floor(Date.now() / 1000)), secret = 'test-only-webhook-secret', queryId = id, bodyId = 'untrusted-body', extraQuery = '' } = {}) {
  const requestId = 'test-request-1';
  const hash = createHmac('sha256', secret).update(`id:${id.toLowerCase()};request-id:${requestId};ts:${timestamp};`).digest('hex');
  return new Request(`https://store.example.test/api/payments/webhook?data.id=${encodeURIComponent(queryId)}${extraQuery}`, {
    method: 'POST', headers: { 'x-request-id': requestId, 'x-signature': `ts=${timestamp},v1=${hash}` },
    body: JSON.stringify({ data: { id: bodyId }, status: 'approved' }),
  });
}

test('shipping includes every physical unit and uses selectable prices including tax', async t => {
  const calls = mockFetch(t, () => Response.json(quoteResponse));
  const quotes = await quoteShipping(env, products, cart, { postcode: '1000', city: 'CABA', state: 'CABA' });
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'https://api.zipnova.com.ar/v2/shipments/quote');
  assert.deepEqual(calls[0].body.items, [
    { weight: 2100, height: 8, width: 35, length: 45, description: 'Notebook' },
    { weight: 2100, height: 8, width: 35, length: 45, description: 'Notebook' },
    { weight: 175, height: 5, width: 7, length: 12, description: 'Mouse' },
  ]);
  assert.equal(calls[0].body.declared_value, 202.25);
  assert.equal(calls[0].body.origin_id, 52);
  assert.equal(calls[0].body.source, 'api');
  assert.equal(calls[0].body.packages, undefined);
  assert.deepEqual(quotes.map(q => [q.carrier, q.price]), [['Correo de prueba', 12.10]]);
  assert.equal(quotes[0].selection.origin_id, 52, 'resolved city ID must not replace the address-book origin');
  const publicQuote = { ...quotes[0] }; delete publicQuote.selection;
  assert.ok(!JSON.stringify(publicQuote).includes('test-only-'));
  await assert.rejects(quoteShipping(env, products, [{ productId: 'notebook', quantity: 101 }], { postcode: '1000', city: 'CABA', state: 'CABA' }));
  await assert.rejects(quoteShipping(env, [{ ...products[0], weightGrams: 0 }], [{ productId: 'notebook', quantity: 1 }], { postcode: '1000', city: 'CABA', state: 'CABA' }));
  assert.equal(calls.length, 1, 'invalid quantities and dimensions must fail before fetching');
});

test('shipping normalizes small and fractional units only for provider requests', async t => {
  const calls = mockFetch(t, call => call.url.endsWith('/quote') ? Response.json(quoteResponse) : Response.json({ id: 988 }));
  const small = { ...products[1], weightGrams: 1, heightCm: 0.1, widthCm: 0.1, lengthCm: 0.1 };
  const fractional = { ...products[0], weightGrams: 11, heightCm: 1.1, widthCm: 2.25, lengthCm: 4999.9 };
  const smallCart = [{ productId: small.id, quantity: 1 }, { productId: fractional.id, quantity: 1 }];
  const [quote] = await quoteShipping(env, [small, fractional], smallCart, { postcode: '1000', city: 'CABA', state: 'CABA' });
  const expectedUnits = [
    { weight: 10, height: 1, width: 1, length: 1, description: small.name },
    { weight: 11, height: 2, width: 3, length: 5000, description: fractional.name },
  ];
  assert.deepEqual(calls[0].body.items, expectedUnits);
  assert.deepEqual(quote.selection.items, expectedUnits);
  assert.deepEqual([small.weightGrams, small.heightCm, small.widthCm, small.lengthCm], [1, 0.1, 0.1, 0.1], 'provider normalization must not mutate product measures');
  assert.deepEqual([fractional.heightCm, fractional.widthCm, fractional.lengthCm], [1.1, 2.25, 4999.9]);
  const smallOrder = { ...order, total: 114.25, items: [
    { ...order.items[1], quantity: 1 }, { ...order.items[0], quantity: 1 },
  ] };
  await createShipment(env, smallOrder, quote.selection);
  assert.deepEqual(calls[1].body.items, expectedUnits, 'shipment must retain the normalized per-unit quote');
  assert.deepEqual(smallOrder.items.map(item => [item.quantity, item.unitPrice]), [[1, 2.05], [1, 100.10]]);
  for (const invalid of [{ weightGrams: 0 }, { weightGrams: 1.5 }, { heightCm: 0 }, { widthCm: NaN }, { lengthCm: Infinity }, { lengthCm: 5000.1 }]) {
    await assert.rejects(quoteShipping(env, [{ ...small, ...invalid }], [{ productId: small.id, quantity: 1 }], { postcode: '1000', city: 'CABA', state: 'CABA' }));
  }
  assert.equal(calls.length, 2, 'invalid measures must fail before provider requests');
});

test('payment uses ordered snapshots and charges the shipping amount exactly once', async t => {
  const calls = mockFetch(t, () => Response.json({ id: 'preference-test', init_point: 'https://www.mercadopago.com.ar/checkout?pref_id=preference-test' }));
  const result = await createPayment(env, order, 'https://store.example.test');
  assert.equal(result.id, 'preference-test');
  const body = calls[0].body;
  assert.deepEqual(body.items.map(item => [item.title, item.quantity, item.currency_id, item.unit_price]), [
    ['Notebook comprado', 2, 'ARS', 100.10], ['Mouse comprado', 1, 'ARS', 2.05],
  ]);
  assert.equal(body.shipments.cost, 12.10);
  assert.equal(body.shipments.mode, 'not_specified');
  const chargedCents = body.items.reduce((sum, item) => sum + Math.round(item.unit_price * 100) * item.quantity, 0) + Math.round(body.shipments.cost * 100);
  assert.equal(chargedCents, 21435);
  assert.equal(body.external_reference, order.id);
  assert.equal(body.notification_url, 'https://store.example.test/api/payments/webhook');
  assert.equal(body.back_urls.success, 'https://store.example.test/cuenta?payment=success');
  await assert.rejects(createPayment(env, { ...order, total: 215.35 }, 'https://store.example.test'));
  assert.equal(calls.length, 1, 'a mismatched total must fail before creating a preference');
});

test('webhook accepts a correct signed query ID and denies tampering, duplicates and stale signatures', async t => {
  const calls = mockFetch(t, () => { throw new Error('No network is allowed during webhook verification'); });
  const secret = 'test-only-webhook-secret';
  assert.equal(await validateMPWebhook(signedRequest({ id: 'ORD-AbC' }), secret), 'ORD-AbC');
  assert.equal(await validateMPWebhook(signedRequest({ timestamp: String(Date.now()) }), secret), '123');
  assert.equal(await validateMPWebhook(signedRequest({ queryId: '456' }), secret), null);
  assert.equal(await validateMPWebhook(signedRequest(), 'different-test-secret'), null);
  assert.equal(await validateMPWebhook(signedRequest({ timestamp: String(Math.floor(Date.now() / 1000) - 600) }), secret), null);
  assert.equal(await validateMPWebhook(signedRequest({ timestamp: String(Math.floor(Date.now() / 1000) + 600) }), secret), null);
  assert.equal(await validateMPWebhook(signedRequest({ extraQuery: '&data.id=456' }), secret), null);
  assert.equal(await validateMPWebhook(new Request('https://store.example.test?data.id=123'), secret), null);
  assert.equal(calls.length, 0);
});

test('payment reconciliation selects the requested order and never exposes provider errors', async t => {
  const base = { id: 123, external_reference: order.id, currency_id: 'ARS', transaction_amount: 214.35, status: 'approved' };
  const calls = mockFetch(t, call => call.url.includes('/search') ? Response.json({ results: [base, { ...base, id: 456, external_reference: 'other-order' }] }) : Response.json(base));
  assert.deepEqual(await fetchPayment(env, '123'), { id: '123', externalReference: order.id, currency: 'ARS', total: 214.35, status: 'approved' });
  assert.deepEqual((await findPayments(env, order.id)).map(payment => payment.id), ['123']);
  const searchUrl = new URL(calls[1].url);
  assert.equal(searchUrl.searchParams.get('external_reference'), order.id);
  assert.equal(searchUrl.searchParams.get('limit'), '20');
  await assert.rejects(fetchPayment(env, '../123'));
  assert.equal(calls.length, 2);
  t.mock.method(globalThis, 'fetch', async () => new Response(JSON.stringify({ message: `${env.MERCADOPAGO_ACCESS_TOKEN} ${env.ZIPNOVA_API_SECRET}` }), { status: 401 }));
  await assert.rejects(fetchPayment(env, '123'), error => error.message.includes('credenciales') && !error.message.includes('test-only-'));
});

test('shipment reuses the retained parcel quote and rejects a changed amount or address', async t => {
  const calls = mockFetch(t, call => call.url.endsWith('/quote') ? Response.json(quoteResponse) : Response.json({ id: 987, carrier_tracking_id: 'TRACK-987', tracking: 'https://tracking.example.test/987' }));
  const [quote] = await quoteShipping(env, products, cart, { postcode: '1000', city: 'CABA', state: 'CABA' });
  const result = await createShipment(env, order, quote.selection);
  assert.deepEqual(result, { id: '987', trackingCode: 'TRACK-987', trackingUrl: 'https://tracking.example.test/987' });
  const body = calls[1].body;
  assert.equal(body.items.length, 3);
  assert.deepEqual(body.items, calls[0].body.items);
  assert.equal(body.origin_id, '52');
  assert.equal(body.carrier_id, 63);
  assert.equal(body.service_type, 'standard_delivery');
  assert.equal(body.destination.street_number, '123');
  assert.equal(body.destination.document, '12345678');
  assert.equal(body.process_immediately, 0);
  assert.ok(/^[A-Za-z0-9-]{1,30}$/.test(body.external_id));
  await createShipment(env, order, quote.selection);
  assert.equal(calls[2].body.external_id, body.external_id, 'retries must retain a stable external shipment ID');
  await assert.rejects(createShipment(env, order, { ...quote.selection, quote_price: 1 }));
  await assert.rejects(createShipment(env, { ...order, address: { ...address, postcode: '2000' } }, quote.selection));
  assert.equal(calls.length, 3, 'changed quotes must not produce a shipment request');
});
