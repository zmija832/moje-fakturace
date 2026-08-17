const PREVIEW_FORM_FIELDS = [
    'currency',
    'taxable_supply_on',
    'payment_method',
    'invoice_discount_type',
    'invoice_discount_value',
];

export function buildInvoicePreviewFormData(form, items, isVatPayer) {
    const body = new FormData();

    PREVIEW_FORM_FIELDS.forEach((field) => {
        const control = form.elements.namedItem(field);
        if (control && 'value' in control) body.append(field, String(control.value ?? ''));
    });

    items.forEach((item, index) => {
        const prefix = `items[${index}]`;
        body.append(`${prefix}[position]`, String(index + 1));
        body.append(`${prefix}[description]`, String(item.description ?? ''));
        body.append(`${prefix}[quantity]`, String(item.quantity ?? ''));
        body.append(`${prefix}[unit]`, String(item.unit ?? ''));
        body.append(`${prefix}[unit_price]`, String(item.unit_price ?? ''));
        body.append(`${prefix}[discount_type]`, String(item.discount_type ?? 'none'));
        body.append(`${prefix}[discount_value]`, String(item.discount_value ?? '0'));
        if (isVatPayer) {
            body.append(`${prefix}[vat_rate_uuid]`, String(item.vat_rate_uuid ?? ''));
        }
    });

    return body;
}
