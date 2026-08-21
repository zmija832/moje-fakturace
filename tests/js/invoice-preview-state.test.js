import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoicePreviewResponse } from '../../resources/js/invoice-preview-state.js';

const item = (key) => ({ _editorKey: key, _previewLineTotal: null });
const response = (...totals) => ({
    display: {
        items: totals.map((lineTotal, index) => ({ position: index + 1, line_total_amount: lineTotal })),
        totals: { grand_total: totals.join('+') },
    },
});

test('server preview maps every current row deterministically by position', () => {
    const items = [item('first'), item('second'), item('third')];
    applyInvoicePreviewResponse(items, response('400', '100', '333'));
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['400', '100', '333']);
});

test('remove and reorder use the current contiguous server positions', () => {
    const first = item('first');
    const second = item('second');
    const third = item('third');
    const items = [first, second, third];

    items.splice(1, 1);
    items.reverse();
    applyInvoicePreviewResponse(items, response('300', '100'));

    assert.deepEqual(items.map((row) => row._editorKey), ['third', 'first']);
    assert.deepEqual(items.map((row) => row._previewLineTotal), ['300', '100']);
});
