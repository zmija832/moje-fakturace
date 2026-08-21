import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoiceCatalogSelection } from '../../resources/js/invoice-catalog-selection.js';
import { createInvoicePreviewPipeline } from '../../resources/js/invoice-preview-pipeline.js';
import { applyInvoicePreviewResponse } from '../../resources/js/invoice-preview-state.js';

const item = (key, price = '0') => ({
    _editorKey: key, _catalogResults: [], _catalogRequest: 0, _previewLineTotal: null,
    description: '', quantity: '1', unit: 'ks', unit_price: price,
    discount_type: 'none', discount_value: '0',
});

const serverResponse = (lineTotals, grandTotal) => ({
    display: {
        items: lineTotals.map((amount, index) => ({ position: index + 1, line_total_amount: amount })),
        totals: { grand_total: grandTotal },
        currency: 'CZK',
    },
});

function harness(items, responses = []) {
    let timer = null;
    let loading = false;
    let total = null;
    let error = '';
    const requests = [];
    const pipeline = createInvoicePreviewPipeline({
        buildBody: () => {
            const body = new FormData();
            items.forEach((invoiceItem, index) => {
                body.append(`items[${index}][position]`, String(index + 1));
                body.append(`items[${index}][description]`, invoiceItem.description);
                body.append(`items[${index}][unit_price]`, invoiceItem.unit_price);
            });

            return body;
        },
        send: async (body, signal) => {
            requests.push({ body, signal });
            const response = responses.shift();
            if (response instanceof Promise) return response;

            return response;
        },
        apply: (response) => {
            applyInvoicePreviewResponse(items, response);
            total = response.display.totals.grand_total;
        },
        fail: () => { error = 'Náhled nyní nelze vypočítat.'; },
        loading: (value) => { loading = value; },
        setTimer: (callback) => { timer = callback; return callback; },
        clearTimer: (candidate) => { if (timer === candidate) timer = null; },
    });

    return {
        pipeline,
        requests,
        loading: () => loading,
        total: () => total,
        error: () => error,
        flush: async () => {
            const callback = timer;
            timer = null;
            assert.equal(typeof callback, 'function');

            return callback();
        },
    };
}

async function selectCatalog(items, index, price, queuePreview) {
    const current = items[index];
    const selected = applyInvoiceCatalogSelection(current, {
        name: `Katalog ${price}`, unit: 'ks', unit_price: price, currency: 'CZK',
    }, false);
    selected._catalogRequest = (current._catalogRequest ?? 0) + 1;
    selected._catalogResults = [];
    items.splice(index, 1, selected);
    await Promise.resolve();
    queuePreview();
}

test('manual input 0 to 400 applies row and invoice total and clears global loading', async () => {
    const items = [item('first')];
    const preview = harness(items, [serverResponse(['400'], '400')]);

    items[0].unit_price = '400';
    preview.pipeline.queue();
    await preview.flush();

    assert.equal(items[0]._previewLineTotal, '400');
    assert.equal(preview.total(), '400');
    assert.equal(preview.loading(), false);
});

test('catalog 400 then catalog 100 is applied immediately before manual third row reaches 833', async () => {
    const items = [item('first')];
    const preview = harness(items, [
        serverResponse(['400'], '400'),
        serverResponse(['400', '100'], '500'),
        serverResponse(['400', '100', '333'], '833'),
    ]);

    await selectCatalog(items, 0, '400', () => preview.pipeline.queue());
    await preview.flush();
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['400']);
    assert.equal(preview.total(), '400');

    items.push(item('second'));
    await selectCatalog(items, 1, '100', () => preview.pipeline.queue());
    await preview.flush();
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['400', '100']);
    assert.equal(preview.total(), '500');

    items.push(item('third'));
    items[2].unit_price = '333';
    preview.pipeline.queue();
    await preview.flush();
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['400', '100', '333']);
    assert.equal(preview.total(), '833');
    assert.equal(preview.loading(), false);
    assert.equal(preview.requests.length, 3);
});

test('newest request wins and aborts an older request during rapid 100 200 300 changes', async () => {
    const items = [item('first', '100')];
    let resolveOld;
    const oldResponse = new Promise((resolve) => { resolveOld = resolve; });
    const preview = harness(items, [oldResponse, serverResponse(['300'], '300')]);

    const first = preview.pipeline.refresh();
    items[0].unit_price = '200';
    preview.pipeline.queue();
    items[0].unit_price = '300';
    preview.pipeline.queue();
    await preview.flush();

    assert.equal(preview.requests[0].signal.aborted, true);
    assert.equal(items[0]._previewLineTotal, '300');
    assert.equal(preview.total(), '300');
    resolveOld(serverResponse(['100'], '100'));
    await first;
    assert.equal(items[0]._previewLineTotal, '300');
    assert.equal(preview.loading(), false);
});

test('add remove and reorder keep request item indexes contiguous with current items', async () => {
    const items = [item('first', '100'), item('second', '200'), item('third', '300')];
    items.splice(1, 1);
    items.reverse();
    const preview = harness(items, [serverResponse(['300', '100'], '400')]);

    preview.pipeline.queue();
    await preview.flush();

    const names = [...preview.requests[0].body.keys()];
    assert.deepEqual(names.filter((name) => name.endsWith('[position]')), [
        'items[0][position]', 'items[1][position]',
    ]);
    assert.equal(names.some((name) => name.startsWith('items[2]')), false);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['300', '100']);
});

test('failed preview keeps input state and exposes one shared retry error', async () => {
    const items = [item('first', '400')];
    const preview = harness(items, [Promise.reject(new Error('server failure'))]);

    preview.pipeline.queue();
    await preview.flush();

    assert.equal(items[0].unit_price, '400');
    assert.equal(preview.error(), 'Náhled nyní nelze vypočítat.');
    assert.equal(preview.loading(), false);
});
