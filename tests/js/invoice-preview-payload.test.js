import assert from 'node:assert/strict';
import test from 'node:test';

import { buildInvoicePreviewFormData } from '../../resources/js/invoice-preview-payload.js';

function editForm() {
    const controls = new Map(Object.entries({
        _token: { value: 'csrf-token' },
        _method: { value: 'PUT' },
        version: { value: '3' },
        correlation_uuid: { value: '4b36de0b-fdc2-487b-9086-92ddba771981' },
        customer_uuid: { value: 'db5f97d7-3db2-43c2-b721-3c315a41144e' },
        bank_account_uuid: { value: '94908ac8-5e75-4d71-a9fd-135d626989c4' },
        currency: { value: 'CZK' },
        issued_on: { value: '2026-08-17' },
        taxable_supply_on: { value: '2026-08-17' },
        due_on: { value: '2026-08-31' },
        payment_method: { value: 'bank_transfer' },
        variable_symbol: { value: '20260001' },
        note: { value: 'Unrelated transport field' },
        invoice_discount_type: { value: 'none' },
        invoice_discount_value: { value: '0' },
    }));

    return { elements: { namedItem: (name) => controls.get(name) ?? null } };
}

test('edit and duplicated draft preview payload cannot spoof the POST route to PUT', () => {
    const body = buildInvoicePreviewFormData(editForm(), [{
        description: 'Design work',
        quantity: '1',
        unit: 'ks',
        unit_price: '505',
        discount_type: 'none',
        discount_value: '0',
        vat_rate_uuid: '48557c4d-e698-4156-949f-b4ba1ced0db9',
    }], true);

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

test('non-payer preview payload never sends a VAT UUID', () => {
    const body = buildInvoicePreviewFormData(editForm(), [{
        description: 'Service', quantity: '2', unit: 'h', unit_price: '250',
        discount_type: 'none', discount_value: '0', vat_rate_uuid: 'forged',
    }], false);

    assert.equal(body.get('items[0][vat_rate_uuid]'), null);
    assert.equal(body.get('items[0][unit_price]'), '250');
});
