export function applyInvoiceCatalogSelection(item, catalogItem, isVatPayer, schedulePreview) {
    if (!item || !catalogItem || catalogItem.currency == null) return false;

    item.description = catalogItem.name;
    item.unit = catalogItem.unit;

    if (catalogItem.unit_price !== null && catalogItem.unit_price !== undefined && catalogItem.unit_price !== '') {
        item.unit_price = catalogItem.unit_price;
    }

    if (isVatPayer && catalogItem.vat_rate_uuid) item.vat_rate_uuid = catalogItem.vat_rate_uuid;

    schedulePreview();

    return true;
}
