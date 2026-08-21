import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoiceCatalogSelection } from '../../resources/js/invoice-catalog-selection.js';

test('priced catalog selection updates invoice fields and schedules authoritative preview', () => {
    const item = { description: '', unit: 'ks', unit_price: '0', vat_rate_uuid: '' };
    let scheduled = 0;

    const applied = applyInvoiceCatalogSelection(item, {
        name: 'Grafické práce',
        unit: 'hod',
        unit_price: '500',
        currency: 'CZK',
        vat_rate_uuid: 'rate-uuid',
    }, true, () => { scheduled += 1; });

    assert.equal(applied, true);
    assert.deepEqual(item, {
        description: 'Grafické práce',
        unit: 'hod',
        unit_price: '500',
        vat_rate_uuid: 'rate-uuid',
    });
    assert.equal(scheduled, 1);
});

test('catalog selection without price preserves current invoice price and still schedules preview', () => {
    const item = { description: '', unit: 'ks', unit_price: '275' };
    let scheduled = 0;

    applyInvoiceCatalogSelection(item, {
        name: 'Individuální práce',
        unit: 'hod',
        unit_price: null,
        currency: 'CZK',
        vat_rate_uuid: null,
    }, false, () => { scheduled += 1; });

    assert.equal(item.description, 'Individuální práce');
    assert.equal(item.unit, 'hod');
    assert.equal(item.unit_price, '275');
    assert.equal(scheduled, 1);
    assert.equal('vat_rate_uuid' in item, false);
});
