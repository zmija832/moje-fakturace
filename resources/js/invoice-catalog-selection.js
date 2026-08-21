export function applyInvoiceCatalogSelection(item, catalogItem, isVatPayer) {
    if (!item || !catalogItem || catalogItem.currency == null) return null;

    const selectedItem = {
        ...item,
        description: catalogItem.name,
        unit: catalogItem.unit,
    };

    if (catalogItem.unit_price !== null && catalogItem.unit_price !== undefined && catalogItem.unit_price !== '') {
        selectedItem.unit_price = catalogItem.unit_price;
    }

    if (isVatPayer && catalogItem.vat_rate_uuid) selectedItem.vat_rate_uuid = catalogItem.vat_rate_uuid;

    return selectedItem;
}

export async function applyInvoiceCatalogSelectionLifecycle({
    items,
    index,
    catalogItem,
    isVatPayer,
    invalidatePreview,
    isPreviewCurrent,
    nextTick,
    schedulePreview,
}) {
    const item = items[index];
    const selectedItem = applyInvoiceCatalogSelection(item, catalogItem, isVatPayer);
    if (!selectedItem) return false;

    const previewGeneration = invalidatePreview();
    selectedItem._catalogRequest = (item._catalogRequest ?? 0) + 1;
    selectedItem._catalogResults = [];
    items.splice(index, 1, selectedItem);

    await nextTick();
    if (!isPreviewCurrent(previewGeneration)) return true;
    schedulePreview();

    return true;
}
