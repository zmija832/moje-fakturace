import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoiceCatalogSelection } from '../../resources/js/invoice-catalog-selection.js';
import { buildInvoicePreviewFormData } from '../../resources/js/invoice-preview-payload.js';

function item(key, overrides = {}) {
    return {
        _editorKey: key, _catalogResults: [], _catalogRequest: 0, _previewLineTotal: null,
        description: '', quantity: '1', unit: 'ks', unit_price: '0',
        discount_type: 'none', discount_value: '0', ...overrides,
    };
}

function form(items) {
    const controls = Object.entries({
        currency: 'CZK', taxable_supply_on: '2026-08-21', payment_method: 'bank_transfer',
        invoice_discount_type: 'none', invoice_discount_value: '0',
    }).map(([name, value]) => ({ name, value }));

    items.forEach((invoiceItem, index) => {
        const fields = {
            position: index + 1, description: invoiceItem.description, quantity: invoiceItem.quantity,
            unit: invoiceItem.unit, unit_price: invoiceItem.unit_price,
            discount_type: invoiceItem.discount_type, discount_value: invoiceItem.discount_value,
            ...(invoiceItem.vat_rate_uuid === undefined ? {} : { vat_rate_uuid: invoiceItem.vat_rate_uuid }),
        };
        Object.entries(fields).forEach(([field, value]) => controls.push({
            name: `items[${index}][${field}]`, value: String(value ?? ''),
        }));
    });
    controls.namedItem = (name) => controls.find((control) => control.name === name) ?? null;

    return { elements: controls };
}

test('catalog selection updates the authoritative item state', () => {
    const items = [item('first')];
    items.splice(0, 1, applyInvoiceCatalogSelection(items[0], {
        name: 'Katalog 400', unit: 'ks', unit_price: '400', currency: 'CZK',
    }, false));

    assert.equal(items[0].description, 'Katalog 400');
    assert.equal(items[0].unit_price, '400');
});

test('catalog item without price preserves the current price and non-payer sends no VAT field', () => {
    const items = [item('first', { unit_price: '275' })];
    items.splice(0, 1, applyInvoiceCatalogSelection(items[0], {
        name: 'Individuální práce', unit: 'hod', unit_price: null, currency: 'CZK',
    }, false));

    const body = buildInvoicePreviewFormData(form(items), false);
    assert.equal(body.get('items[0][description]'), 'Individuální práce');
    assert.equal(body.get('items[0][unit]'), 'hod');
    assert.equal(body.get('items[0][unit_price]'), '275');
    assert.equal(body.has('items[0][vat_rate_uuid]'), false);
});

test('payer catalog selection keeps VAT in the whitelisted form payload', () => {
    const items = [item('first', { vat_rate_uuid: 'old-rate' })];
    items.splice(0, 1, applyInvoiceCatalogSelection(items[0], {
        name: 'Plátcovská položka', unit: 'ks', unit_price: '100', currency: 'CZK', vat_rate_uuid: 'new-rate',
    }, true));

    assert.equal(buildInvoicePreviewFormData(form(items), true).get('items[0][vat_rate_uuid]'), 'new-rate');
});
