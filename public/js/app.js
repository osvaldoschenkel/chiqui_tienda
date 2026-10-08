(() => {
    'use strict';

    const navButtons = document.querySelectorAll('[data-nav-toggle]');
    const setNav = (open) => {
        document.body.classList.toggle('nav-open', open);
        navButtons.forEach((button) => button.setAttribute('aria-expanded', String(open)));
    };
    navButtons.forEach((button) => button.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open'))));
    document.querySelectorAll('[data-nav-close]').forEach((element) => element.addEventListener('click', () => setNav(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setNav(false); });
    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); });
    });
    document.querySelectorAll('form[data-submit-once]').forEach((form) => {
        let submitted = false;
        const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            if (submitted || !form.reportValidity()) { event.preventDefault(); return; }
            submitted = true;
            buttons.forEach((button) => { button.disabled = true; });
        });
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) { submitted = false; buttons.forEach((button) => { button.disabled = false; }); }
        });
    });

    const imageInput = document.querySelector('input[type="file"][name="image"]');
    if (imageInput) {
        let previewUrl;
        imageInput.addEventListener('change', () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            const file = imageInput.files[0];
            if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
            const container = imageInput.closest('.image-upload');
            if (!container) return;
            let preview = container.querySelector('.image-preview');
            if (!preview) { preview = document.createElement('img'); preview.className = 'image-preview'; container.prepend(preview); }
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            preview.alt = 'Vista previa de la imagen elegida';
        });
    }

    const form = document.querySelector('[data-order-form]');
    const catalog = document.getElementById('order-catalog');
    if (!form || !catalog) return;
    const purchase = form.dataset.orderKind === 'purchases';
    let seed;
    try { seed = JSON.parse(catalog.textContent); } catch { return; }
    const products = new Map(seed.products.map((product) => [String(product.id), product]));
    const cart = new Map();
    const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 2 });
    const amount = (cents) => money.format(cents / 100);
    const cents = (value) => {
        const match = String(value).trim().match(/^(\d{1,9})(?:[.,](\d{1,2}))?$/);
        return match ? Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0')) : null;
    };
    const toDecimal = (value) => `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;
    const itemsContainer = form.querySelector('[data-cart-items]');
    const hiddenContainer = form.querySelector('[data-cart-hidden]');
    const empty = form.querySelector('[data-cart-empty]');
    const count = form.querySelector('[data-cart-count]');
    const totalElement = form.querySelector('[data-cart-total]');
    const submit = form.querySelector('[data-order-submit]');
    const message = form.querySelector('[data-cart-message]');
    const contact = form.querySelector('[data-order-contact]');
    const payment = form.querySelector('[data-payment-method]');
    const paymentHint = form.querySelector('[data-payment-hint]');
    let busy = false;
    const create = (tag, className, content) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (content !== undefined) element.textContent = content;
        return element;
    };
    const hidden = (name, value) => { const input = create('input'); input.type = 'hidden'; input.name = name; input.value = String(value); hiddenContainer.append(input); };

    const update = () => {
        hiddenContainer.replaceChildren();
        let total = 0;
        let units = 0;
        let error = '';
        Array.from(cart.values()).forEach((item, index) => {
            const unit = purchase ? cents(item.cost) : item.product.price;
            const validQuantity = Number.isInteger(item.quantity) && item.quantity >= 1 && item.quantity <= 1000000;
            if (!products.has(String(item.product.id))) error = 'Quitá los productos que ya no están disponibles.';
            else if (!validQuantity) error = 'Ingresá cantidades enteras entre 1 y 1.000.000.';
            else if (!purchase && item.quantity > item.product.stock) error = 'La cantidad supera el stock disponible.';
            else if (unit === null) error = 'Ingresá costos válidos con hasta dos decimales.';
            const subtotal = validQuantity && unit !== null ? item.quantity * unit : 0;
            total += subtotal;
            units += validQuantity ? item.quantity : 0;
            item.subtotal.textContent = amount(subtotal);
            hidden(`items[${index}][product_id]`, item.product.id);
            hidden(`items[${index}][quantity]`, item.quantity);
            if (purchase) hidden(`items[${index}][unit_cost]`, item.cost);
        });
        if (!Number.isSafeInteger(total) || total > 9000000000000) error = 'El total supera el máximo permitido.';
        if (cart.size && total === 0 && !error) error = 'El total debe ser mayor que cero.';
        count.textContent = `${units} ${units === 1 ? 'unidad' : 'unidades'}`;
        empty.hidden = cart.size > 0;
        totalElement.textContent = amount(total);
        message.textContent = error;
        submit.disabled = busy || !cart.size || !!error;
    };

    const render = () => {
        itemsContainer.replaceChildren();
        cart.forEach((item, id) => {
            const row = create('div', 'cart-row');
            const top = create('div', 'cart-row-top');
            const title = create('div');
            title.append(create('strong', '', item.product.name), create('span', 'row-sub', item.product.sku || ''));
            const remove = create('button', 'cart-remove', '×');
            remove.type = 'button'; remove.setAttribute('aria-label', `Quitar ${item.product.name}`);
            remove.addEventListener('click', () => { cart.delete(id); render(); });
            top.append(title, remove); row.append(top);
            if (purchase) {
                const costLabel = create('label', 'cart-unit-cost', 'Costo unitario $');
                const costInput = create('input', 'input'); costInput.type = 'text'; costInput.inputMode = 'decimal'; costInput.value = item.cost; costInput.required = true; costInput.maxLength = 12;
                costInput.setAttribute('aria-label', `Costo unitario de ${item.product.name}`);
                costInput.addEventListener('input', () => { item.cost = costInput.value; update(); });
                costLabel.append(costInput); row.append(costLabel);
            }
            const bottom = create('div', 'cart-row-bottom');
            const controls = create('div', 'quantity-control');
            const decrement = create('button', '', '−'); decrement.type = 'button'; decrement.setAttribute('aria-label', `Restar una unidad de ${item.product.name}`);
            const quantity = create('input'); quantity.type = 'number'; quantity.inputMode = 'numeric'; quantity.min = '1'; quantity.step = '1'; quantity.max = String(purchase ? 1000000 : Math.min(item.product.stock, 1000000)); quantity.value = item.quantity; quantity.required = true;
            quantity.setAttribute('aria-label', `Cantidad de ${item.product.name}`);
            const increment = create('button', '', '+'); increment.type = 'button'; increment.setAttribute('aria-label', `Sumar una unidad de ${item.product.name}`);
            decrement.addEventListener('click', () => { if (item.quantity > 1) { item.quantity--; render(); } });
            increment.addEventListener('click', () => add(id));
            quantity.addEventListener('input', () => { item.quantity = Number(quantity.value); update(); });
            controls.append(decrement, quantity, increment); item.subtotal = create('span', 'money'); bottom.append(controls, item.subtotal); row.append(bottom); itemsContainer.append(row);
        });
        update();
    };

    const add = (id) => {
        const product = products.get(String(id));
        if (!product) return;
        const current = cart.get(String(id));
        if (current && current.quantity >= (purchase ? 1000000 : Math.min(product.stock, 1000000))) { message.textContent = purchase ? 'Alcanzaste la cantidad máxima permitida.' : 'Ya agregaste todo el stock disponible.'; return; }
        if (!current && cart.size >= 200) { message.textContent = 'Podés agregar hasta 200 productos por operación.'; return; }
        if (current) current.quantity = Math.max(1, current.quantity + 1);
        else cart.set(String(id), { product, quantity: 1, cost: toDecimal(product.cost) });
        render();
    };

    const oldItems = Array.isArray(seed.items) ? seed.items : Object.values(seed.items || {});
    oldItems.forEach((old) => {
        const id = String(old.product_id);
        const product = products.get(id) || { id, name: `Producto #${id} (no disponible)`, sku: '', price: 0, cost: 0, stock: 0 };
        const existing = cart.get(id);
        if (existing) existing.quantity += Number(old.quantity);
        else cart.set(id, { product, quantity: Number(old.quantity), cost: String(old.unit_cost ?? toDecimal(product.cost)) });
    });
    document.querySelectorAll('[data-add-product]').forEach((button) => button.addEventListener('click', () => add(button.dataset.addProduct)));
    const search = form.querySelector('[data-catalog-search]');
    if (search) {
        const normalize = (value) => value.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        const filter = () => {
            const query = normalize(search.value);
            let visible = 0;
            form.querySelectorAll('[data-add-product]').forEach((button) => { button.hidden = !normalize(button.dataset.productSearch).includes(query); if (!button.hidden) visible++; });
            const noResults = form.querySelector('[data-catalog-empty]'); if (noResults) noResults.hidden = visible > 0;
            const catalogCount = form.querySelector('[data-catalog-count]'); if (catalogCount) catalogCount.textContent = `${visible} ${visible === 1 ? 'producto disponible' : 'productos disponibles'}`;
        };
        search.addEventListener('input', filter);
        search.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const query = search.value.trim();
            const exact = seed.products.find((product) => (product.barcode && String(product.barcode) === query) || (product.sku && String(product.sku).toLowerCase() === query.toLowerCase()));
            if (exact) { add(String(exact.id)); search.value = ''; filter(); }
        });
    }
    const updatePayment = () => {
        contact.required = purchase || payment.value === 'account';
        paymentHint.textContent = payment.value === 'account' ? (purchase ? 'El total se suma a la deuda con este proveedor.' : 'El total se suma a la deuda de este cliente.') : (purchase ? 'Al confirmar, la mercadería se suma al stock.' : 'Registrá el cobro una vez recibido el pago.');
    };
    payment.addEventListener('change', updatePayment);
    form.addEventListener('submit', (event) => {
        update();
        if (busy || submit.disabled || !form.reportValidity()) { event.preventDefault(); return; }
        busy = true; submit.disabled = true; submit.textContent = 'Registrando…';
    });
    window.addEventListener('pageshow', (event) => { if (event.persisted) { busy = false; submit.textContent = purchase ? 'Registrar compra' : 'Confirmar venta'; update(); } });
    updatePayment(); render();
})();
