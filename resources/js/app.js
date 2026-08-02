import Alpine from 'alpinejs';

Alpine.data('invoiceEditor', (config) => ({
    items: config.items,
    currency: config.currency,
    paymentMethod: config.paymentMethod,
    preview: null,
    previewError: '',
    loading: false,
    errors: config.errors ?? {},
    addItem() {
        this.items.push({ description: '', quantity: '1', unit: 'ks', unit_price: '0', discount_type: 'none', discount_value: '0', vat_rate_uuid: config.defaultVatRateUuid ?? '' });
    },
    removeItem(index) {
        if (this.items.length > 1) this.items.splice(index, 1);
    },
    move(index, offset) {
        const target = index + offset;
        if (target < 0 || target >= this.items.length) return;
        [this.items[index], this.items[target]] = [this.items[target], this.items[index]];
    },
    fieldError(index, field) {
        return this.errors[`items.${index}.${field}`]?.[0] ?? '';
    },
    applyClient(event) {
        const option = event.target.selectedOptions[0];
        if (!option?.value) return;
        if (option.dataset.currency) this.currency = option.dataset.currency;
        if (option.dataset.paymentMethod) this.paymentMethod = option.dataset.paymentMethod;
        const issued = this.$refs.form.elements.issued_on?.value;
        const due = this.$refs.form.elements.due_on;
        if (issued && due && option.dataset.dueDays) {
            const date = new Date(`${issued}T00:00:00Z`);
            date.setUTCDate(date.getUTCDate() + Number(option.dataset.dueDays));
            due.value = date.toISOString().slice(0, 10);
        }
    },
    async refreshPreview() {
        this.loading = true;
        this.previewError = '';
        try {
            const body = new FormData(this.$refs.form);
            body.delete('version');
            body.delete('correlation_uuid');
            const response = await fetch(config.previewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body,
            });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? 'Náhled nyní nelze vypočítat.');
            this.preview = data;
        } catch (error) {
            this.preview = null;
            this.previewError = error.message;
        } finally {
            this.loading = false;
        }
    },
    money(value) {
        return value === null || value === undefined ? '—' : String(value).replace('.', ',');
    },
}));

window.Alpine = Alpine;

Alpine.start();
