export function setInvoiceItemsPreviewUpdating(items, updating) {
    items.forEach((item) => {
        item._previewUpdating = updating;
    });
}

export function applyInvoicePreviewResponse(items, response, requestId, currentRequestId) {
    if (requestId !== currentRequestId) return false;

    const totalsByPosition = new Map();
    const displayItems = Array.isArray(response?.display?.items) ? response.display.items : [];

    displayItems.forEach((previewItem) => {
        const position = Number(previewItem?.position);
        if (Number.isInteger(position) && position > 0 && !totalsByPosition.has(position)) {
            totalsByPosition.set(position, previewItem.line_total_amount ?? null);
        }
    });

    items.forEach((item, index) => {
        item._previewLineTotal = totalsByPosition.get(index + 1) ?? null;
    });

    return true;
}
