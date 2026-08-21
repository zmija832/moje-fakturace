import assert from 'node:assert/strict';
import test from 'node:test';

import { applyInvoiceCatalogSelection } from '../../resources/js/invoice-catalog-selection.js';
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

test('payer catalog selection updates VAT in the same preview state', () => {
    const items = [invoiceItem('first', { vat_rate_uuid: 'old-rate' })];
    select(items, 0, {
        name: 'Plátcovská položka', unit: 'ks', unit_price: '100', currency: 'CZK', vat_rate_uuid: 'new-rate',
    }, true);

    const body = buildInvoicePreviewFormData(form(items), true);
    assert.equal(body.get('items[0][vat_rate_uuid]'), 'new-rate');
});
