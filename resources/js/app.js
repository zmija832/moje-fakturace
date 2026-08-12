import Alpine from 'alpinejs';

Alpine.data('invoiceEditor', (config) => ({
    items: config.items,
    currency: config.currency,
    paymentMethod: config.paymentMethod,
    preview: null,
    previewError: '',
    loading: false,
    errors: config.errors ?? {},
    quickClientOpen: false,
    quickClientType: 'company',
    quickClientErrors: {},
    quickClientGeneralError: '',
    quickClientSubmitting: false,
    quickClientSuccess: '',
    aresLoading: false,
    aresMessage: '',
    aresWarning: '',
    init() {
        if (Object.keys(this.errors).length === 0) return;

        this.$nextTick(() => requestAnimationFrame(() => {
            const firstInvalid = this.$refs.form?.querySelector('[aria-invalid="true"]');
            if (!firstInvalid) return;

            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus({ preventScroll: true });
        }));
    },
    addItem() {
        this.items.push({
            description: '', quantity: '1', unit: 'ks', unit_price: '0',
            discount_type: 'none', discount_value: '0',
            ...(config.isVatPayer ? { vat_rate_uuid: config.defaultVatRateUuid ?? '' } : {}),
        });
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
    hasFieldError(index, field) {
        return this.fieldError(index, field) !== '';
    },
    focusErrorField(id) {
        this.$nextTick(() => {
            const target = document.getElementById(id);
            if (!target) return;

            const focusTarget = target.matches('input, select, textarea, button, [tabindex]')
                ? target
                : target.querySelector('input:not([type="hidden"]), select, textarea, button, [tabindex]');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            focusTarget?.focus({ preventScroll: true });
        });
    },
    openQuickClient() {
        this.quickClientErrors = {};
        this.quickClientGeneralError = '';
        this.quickClientSuccess = '';
        this.aresMessage = '';
        this.aresWarning = '';
        this.quickClientType = 'company';
        this.quickClientOpen = true;
        this.$nextTick(() => this.$refs.quickClientFirst?.focus());
    },
    closeQuickClient() {
        if (!this.quickClientSubmitting && !this.aresLoading) this.quickClientOpen = false;
    },
    quickClientFieldError(field) {
        return this.quickClientErrors[field]?.[0] ?? '';
    },
    async loadQuickClientFromAres() {
        if (this.aresLoading || this.quickClientSubmitting || !config.aresLookupUrl) return;

        const registrationInput = this.$refs.quickClientForm.elements.namedItem('registration_number');
        const ico = String(registrationInput?.value ?? '').replace(/\s+/g, '');
        this.aresMessage = '';
        this.aresWarning = '';
        this.quickClientGeneralError = '';

        if (!/^\d{8}$/.test(ico)) {
            this.quickClientErrors = {
                ...this.quickClientErrors,
                registration_number: ['IČO musí obsahovat přesně 8 číslic.'],
            };
            return;
        }

        registrationInput.value = ico;
        this.quickClientErrors = { ...this.quickClientErrors, registration_number: [] };
        this.aresLoading = true;

        try {
            const response = await fetch(config.aresLookupUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                },
                body: JSON.stringify({ ico }),
            });
            const data = await response.json().catch(() => ({}));

            if (response.status === 422) {
                this.quickClientErrors = {
                    ...this.quickClientErrors,
                    registration_number: data.errors?.ico ?? ['Zadané IČO není platné.'],
                };
                return;
            }

            if (!response.ok || !data.subject) {
                throw new Error(data.message ?? 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.');
            }

            this.quickClientType = 'company';
            for (const field of [
                'company_name', 'registration_number', 'tax_id', 'street',
                'city', 'postal_code', 'country_code',
            ]) {
                const value = data.subject[field];
                const input = this.$refs.quickClientForm.elements.namedItem(field);
                if (input && typeof value === 'string' && value !== '') input.value = value;
            }

            this.aresMessage = 'Údaje byly načteny z ARES. Před uložením je můžete upravit.';
            this.aresWarning = Array.isArray(data.warnings) ? data.warnings.join(' ') : '';
        } catch (error) {
            this.quickClientGeneralError = error instanceof Error
                ? error.message
                : 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.';
        } finally {
            this.aresLoading = false;
        }
    },
    async createQuickClient() {
        if (this.quickClientSubmitting || this.aresLoading || !config.clientStoreUrl) return;
        this.quickClientSubmitting = true;
        this.quickClientErrors = {};
        this.quickClientGeneralError = '';
        try {
            const response = await fetch(config.clientStoreUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: new FormData(this.$refs.quickClientForm),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 422) {
                this.quickClientErrors = data.errors ?? {};
                this.quickClientGeneralError = 'Opravte označená pole.';
                return;
            }
            if (!response.ok || !data.client?.uuid) {
                throw new Error(response.status === 403
                    ? 'Pro vytvoření klienta nemáte oprávnění.'
                    : 'Klienta se nyní nepodařilo vytvořit.');
            }

            const client = data.client;
            const option = new Option(
                `${client.display_name}${client.registration_number ? ` · IČO ${client.registration_number}` : ''}`,
                client.uuid,
                true,
                true,
            );
            option.dataset.currency = client.default_currency ?? '';
            option.dataset.dueDays = client.default_due_days ?? '';
            option.dataset.paymentMethod = client.default_payment_method ?? '';
            this.$refs.customerSelect.add(option);
            this.$refs.customerSelect.value = client.uuid;
            this.applyClient({ target: this.$refs.customerSelect });
            this.$refs.quickClientForm.reset();
            this.quickClientType = 'company';
            this.quickClientOpen = false;
            this.quickClientSuccess = 'Klient byl vytvořen a vybrán.';
            await this.$nextTick();
            await this.refreshPreview();
        } catch (error) {
            this.quickClientGeneralError = error instanceof Error
                ? error.message
                : 'Klienta se nyní nepodařilo vytvořit.';
        } finally {
            this.quickClientSubmitting = false;
        }
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
