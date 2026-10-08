(() => {
    'use strict';
    const root = document.querySelector('[data-dashboard]');
    const initial = document.getElementById('dashboard-data');
    if (!root || !initial) return;
    let data;
    try { data = JSON.parse(initial.textContent); } catch { return; }
    const currency = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 2 });
    const integer = new Intl.NumberFormat('es-AR');
    const percent = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    const money = (cents) => currency.format(cents / 100);
    const create = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return node; };
    const append = (parent, ...nodes) => { parent.append(...nodes); return parent; };
    const link = (url, className) => {
        const node = create('a', className);
        try { const safe = new URL(url, location.origin); node.href = safe.origin === location.origin && ['http:', 'https:'].includes(safe.protocol) ? safe.href : '#'; } catch { node.href = '#'; }
        return node;
    };
    const empty = (title, description) => append(create('div', 'dashboard-empty'), create('h3', '', title), create('p', '', description));
    const paramsFor = (range) => new URLSearchParams(range.preset === 'custom' ? { preset: 'custom', from: range.from, to: range.to } : { preset: range.preset });
    const shortDate = (date) => { const parts = date.split('-'); return `${parts[2]}/${parts[1]}/${parts[0]}`; };
    const form = root.querySelector('[data-range-form]');
    const from = form.querySelector('[name="from"]');
    const to = form.querySelector('[name="to"]');
    const error = root.querySelector('[data-dashboard-error]');
    const updated = root.querySelector('[data-updated]');
    const refresh = root.querySelector('[data-refresh]');
    const auto = root.querySelector('[data-auto-refresh]');
    const chart = root.querySelector('[data-chart]');
    const tooltip = root.querySelector('[data-chart-tooltip]');
    let controller;
    let requestNumber = 0;
    let selectedMetric = 'money';
    let activeIndex = null;
    let graph;
    const drawTable = () => {
        root.querySelector('[data-series-table]').replaceChildren(...data.series.map((point) => append(create('tr'), create('td', '', point.label), create('td', 'text-right', integer.format(point.sales_count)), create('td', 'text-right money', money(point.sales_cents)), create('td', 'text-right', integer.format(point.previous_sales_count)), create('td', 'text-right money', money(point.previous_sales_cents)))));
    };
    const svgElement = (tag, attributes, text) => {
        const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attributes || {}).forEach(([key, value]) => node.setAttribute(key, String(value)));
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const currentValue = (point) => selectedMetric === 'money' ? point.sales_cents : point.sales_count;
    const previousValue = (point) => selectedMetric === 'money' ? point.previous_sales_cents : point.previous_sales_count;
    const xFor = (index) => data.series.length > 1 ? 56 + index / (data.series.length - 1) * 682 : 397;
    const chartValue = (value) => selectedMetric === 'money' ? money(value) : `${integer.format(value)} ${value === 1 ? 'venta' : 'ventas'}`;
    const previousDate = (index) => {
        const date = new Date(`${data.range.previous_from}T12:00:00Z`); date.setUTCDate(date.getUTCDate() + index);
        return `${String(date.getUTCDate()).padStart(2, '0')}/${String(date.getUTCMonth() + 1).padStart(2, '0')}/${date.getUTCFullYear()}`;
    };
    const showPoint = (index, announce = false) => {
        if (!data.series.length || !graph) return;
        activeIndex = Math.max(0, Math.min(data.series.length - 1, index));
        const point = data.series[activeIndex]; const x = xFor(activeIndex); const marker = graph.querySelector('[data-chart-marker]');
        marker.setAttribute('transform', `translate(${x} 0)`); marker.removeAttribute('visibility');
        marker.querySelector('circle').setAttribute('cy', String(222 - currentValue(point) / Number(graph.dataset.maximum) * 190));
        tooltip.setAttribute('aria-live', announce ? 'polite' : 'off');
        tooltip.replaceChildren(create('strong', '', `${shortDate(point.date)} · ${integer.format(point.sales_count)} ${point.sales_count === 1 ? 'venta' : 'ventas'}`), create('p', '', `Período elegido: ${chartValue(currentValue(point))}`), create('p', 'muted', `${previousDate(activeIndex)} anterior: ${chartValue(previousValue(point))}`));
        tooltip.hidden = false;
        tooltip.style.left = `${Math.max(8, Math.min(x / 760 * chart.clientWidth - 85, chart.clientWidth - 215))}px`;
        tooltip.style.top = '12px';
    };
    const hidePoint = () => {
        activeIndex = null; tooltip.hidden = true;
        graph?.querySelector('[data-chart-marker]')?.setAttribute('visibility', 'hidden');
    };
    const drawChart = () => {
        const actualMax = Math.max(0, ...data.series.flatMap((point) => [currentValue(point), previousValue(point)]));
        const maximum = selectedMetric === 'money' ? Math.max(100, Math.ceil(actualMax / 100) * 100) : Math.max(4, Math.ceil(actualMax / 4) * 4);
        graph = svgElement('svg', { viewBox: '0 0 760 270', role: 'img', 'aria-label': selectedMetric === 'money' ? 'Importe diario de ventas en pesos argentinos' : 'Cantidad diaria de ventas', 'data-maximum': maximum });
        graph.append(svgElement('title', {}, 'Evolución diaria de ventas. Tabla de datos disponible debajo.'));
        for (let tick = 0; tick <= 4; tick++) {
            const y = 222 - tick * 47.5; const value = maximum / 4 * tick;
            graph.append(svgElement('line', { x1: 56, y1: y, x2: 738, y2: y, stroke: 'currentColor', opacity: '.09' }));
            const label = selectedMetric === 'money' ? (value >= 100000000 ? `$${integer.format(Math.round(value / 100000000))} M` : value >= 100000 ? `$${integer.format(Math.round(value / 100000))} mil` : money(value)) : integer.format(value);
            graph.append(svgElement('text', { x: 46, y: y + 4, 'text-anchor': 'end', fill: 'currentColor', opacity: '.75', 'font-size': '10' }, label));
        }
        const points = (previous) => data.series.map((point, index) => `${xFor(index)},${222 - (previous ? previousValue(point) : currentValue(point)) / maximum * 190}`);
        const current = points(false);
        if (current.length > 1) graph.append(svgElement('polygon', { points: `56,222 ${current.join(' ')} 738,222`, fill: 'var(--brand, #c45130)', opacity: '.08' }));
        graph.append(svgElement('polyline', { points: points(true).join(' '), fill: 'none', stroke: '#9b968b', 'stroke-width': 2, 'stroke-dasharray': '5 5', 'stroke-linejoin': 'round' }));
        graph.append(svgElement('polyline', { points: current.join(' '), fill: 'none', stroke: 'var(--brand, #c45130)', 'stroke-width': 3, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
        if (data.series.length === 1) graph.append(svgElement('circle', { cx: 397, cy: 222 - previousValue(data.series[0]) / maximum * 190, r: 5, fill: 'none', stroke: '#9b968b', 'stroke-width': 2, 'stroke-dasharray': '3 2' }));
        if (data.series.length <= 14) data.series.forEach((point, index) => graph.append(svgElement('circle', { cx: xFor(index), cy: 222 - currentValue(point) / maximum * 190, r: 3.5, fill: 'var(--brand, #c45130)' })));
        const labelCount = Math.min(chart.clientWidth < 500 ? 3 : 5, data.series.length);
        const labelIndexes = new Set(Array.from({ length: labelCount }, (_, index) => Math.round(index * (data.series.length - 1) / Math.max(1, labelCount - 1))));
        labelIndexes.forEach((index) => graph.append(svgElement('text', { x: xFor(index), y: 251, fill: 'currentColor', opacity: '.75', 'font-size': '11', 'text-anchor': data.series.length === 1 ? 'middle' : index === 0 ? 'start' : (index === data.series.length - 1 ? 'end' : 'middle') }, data.series[index].label)));
        if (!actualMax) graph.append(svgElement('text', { x: 397, y: 126, 'text-anchor': 'middle', fill: 'currentColor', opacity: '.75', 'font-size': '14' }, 'Sin ventas en estos períodos'));
        const marker = svgElement('g', { 'data-chart-marker': '', visibility: 'hidden' });
        marker.append(svgElement('line', { x1: 0, y1: 32, x2: 0, y2: 222, stroke: 'var(--brand, #c45130)', opacity: '.25', 'stroke-dasharray': '3 4' }), svgElement('circle', { cx: 0, cy: 0, r: 6, fill: 'var(--brand, #c45130)', stroke: '#fff', 'stroke-width': 2 }));
        graph.append(marker); chart.querySelector('svg')?.remove(); chart.prepend(graph); hidePoint();
    };
    const renderPayments = () => {
        const maximum = Math.max(1, ...data.payments.map((payment) => payment.total_cents));
        root.querySelector('[data-payments]').replaceChildren(...data.payments.map((payment) => {
            const bar = create('div', 'payment-bar'); const fill = create('span'); fill.style.width = `${payment.total_cents / maximum * 100}%`; bar.append(fill);
            return append(create('div', 'payment-row'), append(create('div', 'payment-head'), create('span', '', payment.label), create('strong', 'money', money(payment.total_cents))), bar, create('small', '', `${integer.format(payment.count)} ${payment.count === 1 ? 'venta' : 'ventas'}`));
        }));
    };
    const renderRanking = () => {
        const container = root.querySelector('[data-ranking]');
        if (!data.top_products.length) { container.replaceChildren(empty('Tu ranking está por empezar', 'Los productos aparecerán acá cuando registres ventas en este período.')); return; }
        const maximum = Math.max(1, ...data.top_products.map((product) => product.quantity)); const list = create('div', 'ranking-list');
        data.top_products.forEach((product, index) => {
            const row = link(product.url, 'ranking-row'); const main = create('div', 'ranking-main'); const bar = create('div', 'ranking-bar'); const fill = create('span'); fill.style.width = `${product.quantity / maximum * 100}%`; bar.append(fill);
            append(main, create('strong', '', product.name), create('small', '', product.sku), bar);
            append(row, create('span', 'ranking-position', index + 1), main, append(create('div', 'ranking-value'), create('strong', '', `${integer.format(product.quantity)} u.`), create('small', 'money', money(product.total_cents)))); list.append(row);
        }); container.replaceChildren(list);
    };
    const renderRecent = () => {
        const container = root.querySelector('[data-recent-sales]');
        if (!data.recent_sales.length) { const state = empty('Sin ventas en este período', 'Cambiá las fechas o registrá tu próxima venta.'); const action = link(root.dataset.saleCreate, 'button button-primary button-small'); action.textContent = 'Nueva venta'; state.append(action); container.replaceChildren(state); return; }
        const wrapper = create('div', 'table-wrap'); const table = create('table', 'responsive-table'); const header = create('thead'); const headerRow = create('tr');
        ['Venta / Cliente', 'Fecha', 'Pago', 'Total'].forEach((text, index) => headerRow.append(create('th', index === 3 ? 'text-right' : '', text))); header.append(headerRow);
        const body = create('tbody');
        data.recent_sales.forEach((sale) => {
            const row = create('tr'); const primary = create('td'); const anchor = link(sale.url, 'row-title'); anchor.textContent = sale.number; append(primary, anchor, create('span', 'row-sub', sale.customer));
            const date = create('td', '', sale.date); date.dataset.label = 'Fecha'; const payment = append(create('td'), create('span', 'badge badge-gray', sale.payment_label)); payment.dataset.label = 'Pago';
            const total = create('td', 'text-right money text-bold', money(sale.total_cents)); total.dataset.label = 'Total'; append(row, primary, date, payment, total); body.append(row);
        }); append(table, header, body); wrapper.append(table); container.replaceChildren(wrapper);
    };
    const renderStock = () => {
        root.querySelector('[data-stock-badge]').textContent = integer.format(data.summary.low_stock_count); const container = root.querySelector('[data-low-stock]');
        if (!data.low_stock.length) { container.replaceChildren(empty('Sin alertas de stock', 'Tu catálogo no tiene productos activos por debajo del mínimo.')); return; }
        const list = create('div', 'low-stock-list');
        data.low_stock.forEach((product) => { const row = link(product.url, 'low-stock-item'); append(row, append(create('div'), create('strong', 'row-title', product.name), create('span', 'row-sub', `${product.sku} · mínimo ${product.min_stock}`)), create('span', 'badge badge-orange', `${product.stock} u.`)); list.append(row); });
        const foot = create('div', 'low-stock-foot'); const action = link(root.dataset.purchaseCreate); action.textContent = 'Registrá una compra para sumar stock. →'; foot.append(action); container.replaceChildren(list, foot);
    };
    const render = (initialRender = false) => {
        root.querySelectorAll('[data-metric]').forEach((node) => { const key = node.dataset.metric; node.textContent = key.endsWith('_cents') ? money(data.summary[key]) : (key === 'sales_count' ? `${integer.format(data.summary[key])} ${data.summary[key] === 1 ? 'venta registrada' : 'ventas registradas'}` : integer.format(data.summary[key])); });
        const comparison = root.querySelector('[data-sales-comparison]'); const change = data.summary.sales_change_percent;
        comparison.textContent = change === null ? (data.summary.sales_cents > 0 ? 'Sin ventas en el período anterior' : 'Sin ventas en ambos períodos') : `${change > 0 ? '+' : ''}${percent.format(change)}% vs. período anterior`;
        comparison.classList.toggle('is-positive', change > 0); comparison.classList.toggle('is-negative', change < 0);
        root.querySelector('[data-range-label]').textContent = data.range.label; root.querySelector('[data-range-dates]').textContent = `${shortDate(data.range.from)} — ${shortDate(data.range.to)}`; root.querySelector('[data-previous-label]').textContent = `${shortDate(data.range.previous_from)} al ${shortDate(data.range.previous_to)}`;
        updated.textContent = `Actualizado ${data.updated_label}`; from.value = data.range.from; to.value = data.range.to;
        root.querySelectorAll('[data-preset]').forEach((anchor) => { const active = anchor.dataset.preset === data.range.preset; anchor.classList.toggle('active', active); if (active) anchor.setAttribute('aria-current', 'true'); else anchor.removeAttribute('aria-current'); });
        drawTable(); drawChart();
        if (!initialRender) { renderPayments(); renderRanking(); renderRecent(); renderStock(); }
    };
    const load = async (params, replaceUrl = false) => {
        controller?.abort(); const localController = new AbortController(); controller = localController; const currentRequest = ++requestNumber; let timedOut = false;
        const timeout = window.setTimeout(() => { timedOut = true; localController.abort(); }, 15000);
        root.setAttribute('aria-busy', 'true'); refresh.disabled = true; updated.textContent = 'Actualizando…'; error.hidden = true;
        try {
            const response = await fetch(`${root.dataset.endpoint}?${params.toString()}`, { signal: localController.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (response.status === 401 || response.status === 419 || (response.redirected && new URL(response.url).pathname.endsWith('/login'))) throw new Error('La sesión venció. Volvé a iniciar sesión para actualizar el resumen.');
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('No pudimos leer la respuesta. Recargá la página para intentarlo de nuevo.');
            const next = await response.json();
            if (!response.ok) throw new Error(next.message || 'No pudimos actualizar estos datos. Revisá las fechas e intentá nuevamente.');
            if (!next.range || !next.summary || !Array.isArray(next.series) || !Array.isArray(next.payments) || !Array.isArray(next.top_products) || !Array.isArray(next.recent_sales) || !Array.isArray(next.low_stock)) throw new Error('La respuesta del resumen está incompleta. Intentá actualizar nuevamente.');
            if (currentRequest !== requestNumber) return;
            data = next; render(); if (replaceUrl) history.replaceState({}, '', `${location.pathname}?${paramsFor(data.range).toString()}`);
        } catch (failure) {
            if (currentRequest !== requestNumber || (failure.name === 'AbortError' && !timedOut)) return;
            error.textContent = timedOut ? 'La actualización demoró demasiado. Se conservan los últimos datos; probá nuevamente.' : failure.message === 'Failed to fetch' ? 'No se pudo conectar. Se conservan los últimos datos; probá actualizar nuevamente.' : failure.message;
            error.hidden = false; updated.textContent = `Últimos datos: ${data.updated_label}`;
        } finally { window.clearTimeout(timeout); if (currentRequest === requestNumber) { root.setAttribute('aria-busy', 'false'); refresh.disabled = false; } }
    };
    root.querySelectorAll('[data-preset]').forEach((anchor) => anchor.addEventListener('click', (event) => { if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return; event.preventDefault(); load(new URLSearchParams({ preset: anchor.dataset.preset }), true); }));
    form.addEventListener('submit', (event) => { event.preventDefault(); to.setCustomValidity(from.value > to.value ? 'La fecha final debe ser igual o posterior a la inicial.' : ''); if (form.reportValidity()) load(new URLSearchParams({ preset: 'custom', from: from.value, to: to.value }), true); });
    from.addEventListener('input', () => to.setCustomValidity('')); to.addEventListener('input', () => to.setCustomValidity(''));
    refresh.addEventListener('click', () => load(paramsFor(data.range)));
    window.addEventListener('popstate', () => load(new URLSearchParams(location.search)));
    root.querySelectorAll('[data-chart-metric]').forEach((button) => button.addEventListener('click', () => { selectedMetric = button.dataset.chartMetric; root.querySelectorAll('[data-chart-metric]').forEach((candidate) => { const selected = candidate === button; candidate.classList.toggle('active', selected); candidate.setAttribute('aria-pressed', String(selected)); }); drawChart(); }));
    const pointerPoint = (event) => { if (!graph) return; const rect = graph.getBoundingClientRect(); const x = (event.clientX - rect.left) / rect.width * 760; showPoint(data.series.length === 1 ? 0 : Math.round((x - 56) / 682 * (data.series.length - 1))); };
    chart.addEventListener('pointermove', pointerPoint); chart.addEventListener('pointerdown', pointerPoint);
    chart.addEventListener('pointerleave', () => { if (document.activeElement !== chart) hidePoint(); });
    chart.addEventListener('focus', () => showPoint(data.series.length - 1, true)); chart.addEventListener('blur', hidePoint);
    chart.addEventListener('keydown', (event) => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return; event.preventDefault(); if (event.key === 'Escape') { hidePoint(); return; } showPoint(event.key === 'Home' ? 0 : event.key === 'End' ? data.series.length - 1 : (activeIndex ?? data.series.length - 1) + (event.key === 'ArrowRight' ? 1 : -1), true); });
    const autoLoad = () => { if (auto.checked && document.visibilityState === 'visible' && root.getAttribute('aria-busy') !== 'true') load(paramsFor(data.range)); };
    let timer = window.setInterval(autoLoad, 60000);
    window.addEventListener('pagehide', () => { controller?.abort(); window.clearInterval(timer); });
    window.addEventListener('pageshow', (event) => { if (event.persisted) { root.setAttribute('aria-busy', 'false'); refresh.disabled = false; timer = window.setInterval(autoLoad, 60000); autoLoad(); } });
    render(true);
})();
