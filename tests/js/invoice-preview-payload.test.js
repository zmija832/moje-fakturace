import assert from 'node:assert/strict';
import test from 'node:test';

import { buildInvoicePreviewFormData } from '../../resources/js/invoice-preview-payload.js';

function formWithItems(items) {
    const controls = [
        ['_token', 'csrf-token'],
        ['_method', 'PUT'],
        ['version', '3'],
        ['correlation_uuid', '4b36de0b-fdc2-487b-9086-92ddba771981'],
        ['customer_uuid', 'db5f97d7-3db2-43c2-b721-3c315a41144e'],
        ['bank_account_uuid', '94908ac8-5e75-4d71-a9fd-135d626989c4'],
        ['currency', 'CZK'],
        ['issued_on', '2026-08-17'],
        ['taxable_supply_on', '2026-08-17'],
        ['due_on', '2026-08-31'],
        ['payment_method', 'bank_transfer'],
        ['variable_symbol', '20260001'],
        ['note', 'Unrelated transport field'],
        ['invoice_discount_type', 'none'],
        ['invoice_discount_value', '0'],
    ].map(([name, value]) => ({ name, value }));

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

test('edit and duplicated draft preview payload cannot spoof the POST route to PUT', () => {
    const form = formWithItems([{
        description: 'Design work',
        quantity: '1',
        unit: 'ks',
        unit_price: '505',
        discount_type: 'none',
        discount_value: '0',
        vat_rate_uuid: '48557c4d-e698-4156-949f-b4ba1ced0db9',
    }]);
    const body = buildInvoicePreviewFormData(form, true);

    assert.equal(body.get('_method'), null);
    assert.equal(body.get('_token'), null);
    assert.equal(body.get('version'), null);
    assert.equal(body.get('correlation_uuid'), null);
    assert.equal(body.get('customer_uuid'), null);
    assert.equal(body.get('bank_account_uuid'), null);
    assert.equal(body.get('issued_on'), null);
    assert.equal(body.get('due_on'), null);
    assert.equal(body.get('variable_symbol'), null);
    assert.equal(body.get('note'), null);
    assert.deepEqual([...body.keys()], [
        'currency',
        'taxable_supply_on',
        'payment_method',
        'invoice_discount_type',
        'invoice_discount_value',
        'items[0][position]',
        'items[0][description]',
        'items[0][quantity]',
        'items[0][unit]',
        'items[0][unit_price]',
        'items[0][discount_type]',
        'items[0][discount_value]',
        'items[0][vat_rate_uuid]',
    ]);
});

test('preview reads visible named item controls including description and unit price', () => {
    const form = formWithItems([{
        description: 'Samolepka vlastní motiv -barva',
        quantity: '1',
        unit: 'ks',
        unit_price: '100',
        discount_type: 'none',
        discount_value: '0',
    }]);
    const body = buildInvoicePreviewFormData(form, false);

    assert.equal(body.get('items[0][description]'), 'Samolepka vlastní motiv -barva');
    assert.equal(body.get('items[0][quantity]'), '1');
    assert.equal(body.get('items[0][unit]'), 'ks');
    assert.equal(body.get('items[0][unit_price]'), '100');
});

test('non-payer preview payload never sends a VAT UUID', () => {
    const form = formWithItems([{
        description: 'Service', quantity: '2', unit: 'h', unit_price: '250',
        discount_type: 'none', discount_value: '0', vat_rate_uuid: 'forged',
    }]);
    const body = buildInvoicePreviewFormData(form, false);

    assert.equal(body.get('items[0][vat_rate_uuid]'), null);
    assert.equal(body.get('items[0][unit_price]'), '250');
});
