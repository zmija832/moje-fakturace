import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoicePreviewResponse, setInvoiceItemsPreviewUpdating } from '../../resources/js/invoice-preview-state.js';

function item(key) {
    return { _editorKey: key, _previewLineTotal: null, _previewUpdating: false };
}

function response(...totals) {
    return {
        display: {
            currency: 'Kč',
            items: totals.map((lineTotal, index) => ({
                position: index + 1,
                line_total_amount: lineTotal,
            })),
            totals: { grand_total: totals.join('+') },
        },
    };
}

test('server preview maps one, two and three rows including the newly added last row', () => {
    const items = [item('first')];

    setInvoiceItemsPreviewUpdating(items, true);
    assert.equal(applyInvoicePreviewResponse(items, response('100'), 1, 1), true);
    setInvoiceItemsPreviewUpdating(items, false);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['100']);

    items.push(item('second'));
    setInvoiceItemsPreviewUpdating(items, true);
    assert.equal(applyInvoicePreviewResponse(items, response('100', '100'), 2, 2), true);
    setInvoiceItemsPreviewUpdating(items, false);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['100', '100']);

    items.push(item('third'));
    setInvoiceItemsPreviewUpdating(items, true);
    assert.equal(applyInvoicePreviewResponse(items, response('100', '100', '300'), 3, 3), true);
    setInvoiceItemsPreviewUpdating(items, false);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['100', '100', '300']);
    assert.deepEqual(items.map((row) => row._previewUpdating), [false, false, false]);
});

test('removing the middle row remaps every remaining stable row by current server position', () => {
    const first = item('first');
    const second = item('second');
    const third = item('third');
    const items = [first, second, third];

    applyInvoicePreviewResponse(items, response('100', '200', '300'), 1, 1);
    items.splice(1, 1);
    setInvoiceItemsPreviewUpdating(items, true);
    applyInvoicePreviewResponse(items, response('100', '600'), 2, 2);
    setInvoiceItemsPreviewUpdating(items, false);

    assert.deepEqual(items.map((row) => row._editorKey), ['first', 'third']);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['100', '600']);
    assert.deepEqual(items.map((row) => row._previewUpdating), [false, false]);
});

test('a slower stale response cannot overwrite totals from the newest request', () => {
    const items = [item('first'), item('second')];

    assert.equal(applyInvoicePreviewResponse(items, response('100', '200'), 2, 2), true);
    assert.equal(applyInvoicePreviewResponse(items, response('1', '2'), 1, 2), false);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['100', '200']);
});
