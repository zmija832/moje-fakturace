import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoiceCatalogSelection, applyInvoiceCatalogSelectionLifecycle } from '../../resources/js/invoice-catalog-selection.js';
import { buildInvoicePreviewFormData } from '../../resources/js/invoice-preview-payload.js';
import { applyInvoicePreviewResponse, setInvoiceItemsPreviewUpdating } from '../../resources/js/invoice-preview-state.js';

function form(items) {
    const controls = Object.entries({
        currency: 'CZK',
        taxable_supply_on: '2026-08-21',
        payment_method: 'bank_transfer',
        invoice_discount_type: 'none',
        invoice_discount_value: '0',
    }).map(([name, value]) => ({ name, value }));

    items.forEach((item, index) => {
        const prefix = `items[${index}]`;
        for (const [field, value] of Object.entries({
            position: index + 1,
            description: item.description,
            quantity: item.quantity,
            unit: item.unit,
            unit_price: item.unit_price,
            discount_type: item.discount_type,
            discount_value: item.discount_value,
            ...(item.vat_rate_uuid === undefined ? {} : { vat_rate_uuid: item.vat_rate_uuid }),
        })) {
            controls.push({ name: `${prefix}[${field}]`, value: String(value ?? '') });
        }
    });

    controls.namedItem = (name) => controls.find((control) => control.name === name) ?? null;

    return { elements: controls };
}

function invoiceItem(key, overrides = {}) {
    return {
        _editorKey: key,
        _catalogResults: [],
        _catalogRequest: 0,
        _previewLineTotal: null,
        _previewUpdating: false,
        description: '',
        quantity: '1',
        unit: 'ks',
        unit_price: '0',
        discount_type: 'none',
        discount_value: '0',
        ...overrides,
    };
}

function select(items, index, catalogItem, isVatPayer = false) {
    const selected = applyInvoiceCatalogSelection(items[index], catalogItem, isVatPayer);
    assert.notEqual(selected, null);
    items.splice(index, 1, selected);
}

function renderItemControls(formElement, items, index) {
    const prefix = `items[${index}]`;
    for (const field of ['description', 'quantity', 'unit', 'unit_price', 'discount_type', 'discount_value']) {
        formElement.elements.namedItem(`${prefix}[${field}]`).value = String(items[index][field] ?? '');
    }
}

test('catalog lifecycle queues the normal preview path only after DOM update', async () => {
    const items = [invoiceItem('first')];
    const invoiceForm = form(items);
    invoiceForm.elements.forEach((control) => { control.form = invoiceForm; });
    const events = [];
    let requestBody = null;

    await applyInvoiceCatalogSelectionLifecycle({
        items,
        index: 0,
        catalogItem: {
            name: 'Samolepka vlastní motiv -barva',
            unit: 'ks',
            unit_price: '100',
            currency: 'CZK',
            vat_rate_uuid: null,
        },
        isVatPayer: false,
        nextTick: async () => {
            events.push('alpine-next-tick');
            renderItemControls(invoiceForm, items, 0);
        },
        queuePreview: () => {
            events.push('queue-normal-preview');
            assert.equal(invoiceForm.elements.namedItem('items[0][description]').value, 'Samolepka vlastní motiv -barva');
            assert.equal(invoiceForm.elements.namedItem('items[0][unit_price]').value, '100');
            assert.equal(invoiceForm.elements.namedItem('items[0][description]').form, invoiceForm);
            requestBody = buildInvoicePreviewFormData(invoiceForm, false);
        },
    });

    assert.deepEqual(events, ['alpine-next-tick', 'queue-normal-preview']);
    assert.equal(requestBody.get('items[0][description]'), 'Samolepka vlastní motiv -barva');
    assert.equal(requestBody.get('items[0][unit_price]'), '100');
});

test('catalog response is not left one preview behind when a form event follows the DOM update', async () => {
    const items = [invoiceItem('first')];
    const invoiceForm = form(items);
    invoiceForm.elements.forEach((control) => { control.form = invoiceForm; });
    const trace = [];
    let generation = 0;
    let lastSignature = null;
    let previewQueued = false;

    const invalidate = (origin) => {
        generation += 1;
        setInvoiceItemsPreviewUpdating(items, true);
        trace.push({ event: 'invalidate', origin, generation });

        return generation;
    };
    const startPreview = (origin, force, totals) => {
        const body = buildInvoicePreviewFormData(invoiceForm, false);
        const signature = JSON.stringify(Array.from(body.entries()));
        trace.push({ event: 'refresh', origin, generation, force, signature });
        if (!force && signature === lastSignature) {
            setInvoiceItemsPreviewUpdating(items, false);
            trace.push({ event: 'skip-same-signature', origin, generation });

            return null;
        }

        lastSignature = signature;
        const requestId = ++generation;
        trace.push({ event: 'request', origin, requestId });

        return { requestId, totals };
    };
    const finishPreview = ({ requestId, totals }, origin) => {
        const applied = applyInvoicePreviewResponse(items, {
            display: {
                items: totals.map((lineTotal, index) => ({ position: index + 1, line_total_amount: lineTotal })),
                totals: { grand_total: totals.reduce((sum, amount) => sum + Number(amount), 0).toString() },
            },
        }, requestId, generation);
        trace.push({ event: applied ? 'apply' : 'discard-stale', origin, requestId, generation });
        if (requestId === generation) setInvoiceItemsPreviewUpdating(items, false);

        return applied;
    };

    await applyInvoiceCatalogSelectionLifecycle({
        items,
        index: 0,
        catalogItem: { name: 'Katalog 400', unit: 'ks', unit_price: '400', currency: 'CZK' },
        isVatPayer: false,
        nextTick: async () => renderItemControls(invoiceForm, items, 0),
        queuePreview: () => {
            invalidate('catalog-normal-path');
            previewQueued = true;
        },
    });

    // A form-wide input/change event is coalesced into the same debounced normal preview.
    invalidate('form-event');
    assert.equal(previewQueued, true);
    const request = startPreview('coalesced-normal-preview', false, ['400']);
    assert.notEqual(request, null);
    assert.equal(finishPreview(request, 'coalesced-normal-preview'), true);

    assert.equal(items[0]._previewLineTotal, '400', JSON.stringify(trace));
    assert.equal(items[0]._previewUpdating, false, JSON.stringify(trace));
});

test('catalog totals are applied immediately for 400 and 100 before a manual third row reaches 833', async () => {
    const items = [invoiceItem('first')];
    let invoiceForm = form(items);
    let generation = 0;
    let previewQueued = false;
    let invoiceTotal = null;

    const render = () => {
        invoiceForm = form(items);
        invoiceForm.elements.forEach((control) => { control.form = invoiceForm; });
    };
    const selectCatalog = async (index, name, price) => {
        await applyInvoiceCatalogSelectionLifecycle({
            items,
            index,
            catalogItem: { name, unit: 'ks', unit_price: price, currency: 'CZK' },
            isVatPayer: false,
            nextTick: async () => render(),
            queuePreview: () => {
                generation += 1;
                previewQueued = true;
                setInvoiceItemsPreviewUpdating(items, true);
            },
        });
    };
    const finishServerPreview = (lineTotals, grandTotal) => {
        assert.equal(previewQueued, true);
        const body = buildInvoicePreviewFormData(invoiceForm, false);
        const indexes = [...body.keys()]
            .map((name) => name.match(/^items\[(\d+)]\[position]$/)?.[1])
            .filter((index) => index !== undefined);
        assert.equal(indexes.length, items.length);
        const requestId = generation;
        assert.equal(applyInvoicePreviewResponse(items, {
            display: {
                items: lineTotals.map((amount, index) => ({ position: index + 1, line_total_amount: amount })),
                totals: { grand_total: grandTotal },
            },
        }, requestId, generation), true);
        invoiceTotal = grandTotal;
        setInvoiceItemsPreviewUpdating(items, false);
        previewQueued = false;

        return body;
    };

    await selectCatalog(0, 'Katalog 400', '400');
    let body = finishServerPreview(['400'], '400');
    assert.equal(body.get('items[0][unit_price]'), '400');
    assert.deepEqual(items.map((item) => item._previewLineTotal), ['400']);
    assert.equal(invoiceTotal, '400');

    items.push(invoiceItem('second'));
    await selectCatalog(1, 'Katalog 100', '100');
    body = finishServerPreview(['400', '100'], '500');
    assert.equal(body.get('items[1][unit_price]'), '100');
    assert.deepEqual(items.map((item) => item._previewLineTotal), ['400', '100']);
    assert.equal(invoiceTotal, '500');

    items.push(invoiceItem('third'));
    items[2].unit_price = '333';
    render();
    generation += 1;
    previewQueued = true;
    setInvoiceItemsPreviewUpdating(items, true);
    body = finishServerPreview(['400', '100', '333'], '833');
    assert.equal(body.get('items[2][unit_price]'), '333');
    assert.deepEqual(items.map((item) => item._previewLineTotal), ['400', '100', '333']);
    assert.equal(invoiceTotal, '833');
    assert.deepEqual(items.map((item) => item._previewUpdating), [false, false, false]);
});

test('catalog selection updates the same item state used by preview payload and rendered total', () => {
    const items = [invoiceItem('first')];
    select(items, 0, {
        name: 'Samolepka vlastní motiv -barva',
        unit: 'ks',
        unit_price: '100',
        currency: 'CZK',
        vat_rate_uuid: null,
    });

    const body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][description]'), 'Samolepka vlastní motiv -barva');
    assert.equal(body.get('items[0][unit]'), 'ks');
    assert.equal(body.get('items[0][unit_price]'), '100');
    assert.notEqual(body.get('items[0][description]'), '');
    assert.notEqual(body.get('items[0][unit_price]'), '0');

    setInvoiceItemsPreviewUpdating(items, true);
    applyInvoicePreviewResponse(items, {
        display: { items: [{ position: 1, line_total_amount: '100' }] },
    }, 1, 1);
    setInvoiceItemsPreviewUpdating(items, false);

    assert.equal(items[0]._previewLineTotal, '100');
    assert.equal(items[0]._previewUpdating, false);
});

test('catalog item without price preserves the current invoice item price', () => {
    const items = [invoiceItem('first', { unit_price: '275' })];
    select(items, 0, {
        name: 'Individuální práce',
        unit: 'hod',
        unit_price: null,
        currency: 'CZK',
        vat_rate_uuid: null,
    });

    const body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][description]'), 'Individuální práce');
    assert.equal(body.get('items[0][unit]'), 'hod');
    assert.equal(body.get('items[0][unit_price]'), '275');
});

test('selection in the last of multiple items updates only that authoritative state entry', () => {
    const items = [
        invoiceItem('first', { description: 'První', unit_price: '50' }),
        invoiceItem('second'),
        invoiceItem('third'),
    ];
    select(items, 2, {
        name: 'Poslední položka', unit: 'bal', unit_price: '100', currency: 'CZK', vat_rate_uuid: null,
    });

    const body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][description]'), 'První');
    assert.equal(body.get('items[0][unit_price]'), '50');
    assert.equal(body.get('items[2][description]'), 'Poslední položka');
    assert.equal(body.get('items[2][unit]'), 'bal');
    assert.equal(body.get('items[2][unit_price]'), '100');
});

test('manual price edit and rapid second catalog selection are reflected by the next payload', () => {
    const items = [invoiceItem('first')];
    select(items, 0, {
        name: 'První výběr', unit: 'ks', unit_price: '100', currency: 'CZK', vat_rate_uuid: null,
    });
    select(items, 0, {
        name: 'Druhý výběr', unit: 'hod', unit_price: '200', currency: 'CZK', vat_rate_uuid: null,
    });

    let body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][description]'), 'Druhý výběr');
    assert.equal(body.get('items[0][unit_price]'), '200');

    items[0].unit_price = '325';
    body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][unit_price]'), '325');
});

test('two catalog selections waiting for DOM update coalesce on the normal preview queue', async () => {
    const items = [invoiceItem('first')];
    const ticks = [];
    let queuedState = null;
    const lifecycle = (name, price) => applyInvoiceCatalogSelectionLifecycle({
        items,
        index: 0,
        catalogItem: { name, unit: 'ks', unit_price: price, currency: 'CZK', vat_rate_uuid: null },
        isVatPayer: false,
        nextTick: () => new Promise((resolve) => ticks.push(resolve)),
        queuePreview: () => { queuedState = { name: items[0].description, price: items[0].unit_price }; },
    });

    const first = lifecycle('První výběr', '100');
    const second = lifecycle('Druhý výběr', '200');
    ticks[0]();
    await first;
    ticks[1]();
    await second;

    assert.deepEqual(queuedState, { name: 'Druhý výběr', price: '200' });
});

test('payer catalog selection updates VAT in the same preview state', () => {
    const items = [invoiceItem('first', { vat_rate_uuid: 'old-rate' })];
    select(items, 0, {
        name: 'Plátcovská položka', unit: 'ks', unit_price: '100', currency: 'CZK', vat_rate_uuid: 'new-rate',
    }, true);

    const body = buildInvoicePreviewFormData(form(items), true);
    assert.equal(body.get('items[0][vat_rate_uuid]'), 'new-rate');
});
