const PREVIEW_FORM_FIELDS = [
    'currency',
    'taxable_supply_on',
    'payment_method',
    'invoice_discount_type',
    'invoice_discount_value',
];

const PREVIEW_ITEM_FIELDS = [
    'description',
    'quantity',
    'unit',
    'unit_price',
    'discount_type',
    'discount_value',
];

function controlValue(form, name) {
    const control = form.elements.namedItem(name);

    return control && 'value' in control ? String(control.value ?? '') : '';
}

export function buildInvoicePreviewFormData(form, isVatPayer) {
    const body = new FormData();

    PREVIEW_FORM_FIELDS.forEach((field) => {
        body.append(field, controlValue(form, field));
    });

    const itemIndexes = [...new Set(Array.from(form.elements)
        .map((control) => String(control.name ?? '').match(/^items\[(\d+)]\[position]$/)?.[1])
        .filter((index) => index !== undefined)
        .map(Number))]
        .sort((left, right) => left - right);

    itemIndexes.forEach((index) => {
        const prefix = `items[${index}]`;
        body.append(`${prefix}[position]`, controlValue(form, `${prefix}[position]`));
        PREVIEW_ITEM_FIELDS.forEach((field) => {
            body.append(`${prefix}[${field}]`, controlValue(form, `${prefix}[${field}]`));
        });
        if (isVatPayer) {
            body.append(`${prefix}[vat_rate_uuid]`, controlValue(form, `${prefix}[vat_rate_uuid]`));
        }
    });

    return body;
}
