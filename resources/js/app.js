import Alpine from 'alpinejs';

Alpine.data('invoiceEditor', (config) => ({
    items: config.items,
    currency: config.currency,
    paymentMethod: config.paymentMethod,
    preview: null,
    previewError: '',
    loading: false,
    previewTimer: null,
    previewController: null,
    previewRequestId: 0,
    lastPreviewSignature: null,
    nextEditorKey: 0,
    draggedItemIndex: null,
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
        this.items.forEach((item) => {
            item._editorKey = this.editorItemKey();
        });

        if (Object.keys(this.errors).length === 0) {
            // Preview has its own deliberately narrower server-side contract. Fields such as
            // customer or bank account must not suppress monetary preview initialization.
            this.$nextTick(() => this.queuePreview(0));

            return;
        }

        this.$nextTick(() => requestAnimationFrame(() => {
            const firstInvalid = this.$refs.form?.querySelector('[aria-invalid="true"]');
            if (!firstInvalid) return;

            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus({ preventScroll: true });
        }));
    },
    destroy() {
        window.clearTimeout(this.previewTimer);
        this.previewController?.abort();
    },
    addItem() {
        this.items.push({
            _editorKey: this.editorItemKey(),
            description: '', quantity: '1', unit: 'ks', unit_price: '0',
            discount_type: 'none', discount_value: '0',
            ...(config.isVatPayer ? { vat_rate_uuid: config.defaultVatRateUuid ?? '' } : {}),
        });
        this.queuePreview();
    },
    removeItem(index) {
        if (this.items.length > 1) {
            this.items.splice(index, 1);
            this.removeItemErrors(index);
            this.removePreviewItem(index);
            this.queuePreview();
        }
    },
    editorItemKey() {
        this.nextEditorKey += 1;

        return `invoice-item-${this.nextEditorKey}`;
    },
    startItemDrag(event, index) {
        this.draggedItemIndex = index;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    },
    endItemDrag() {
        this.draggedItemIndex = null;
    },
    dropItem(target) {
        const source = this.draggedItemIndex;
        if (Number.isInteger(source)) this.reorderItem(source, target);
        this.endItemDrag();
    },
    moveItemByOffset(index, offset) {
        this.reorderItem(index, index + offset);
    },
    reorderItem(source, target) {
        if (source === target || source < 0 || target < 0 || source >= this.items.length || target >= this.items.length) return;

        const [item] = this.items.splice(source, 1);
        this.items.splice(target, 0, item);
        this.moveItemErrors(source, target);
        this.reorderPreviewItems(source, target);
        this.queuePreview();
    },
    moveItemErrors(source, target) {
        const remapped = {};
        for (const [key, messages] of Object.entries(this.errors)) {
            const match = key.match(/^items\.(\d+)\.(.+)$/);
            if (!match) {
                remapped[key] = messages;
                continue;
            }

            const index = Number(match[1]);
            let nextIndex = index;
            if (index === source) nextIndex = target;
            else if (source < target && index > source && index <= target) nextIndex = index - 1;
            else if (source > target && index >= target && index < source) nextIndex = index + 1;
            remapped[`items.${nextIndex}.${match[2]}`] = messages;
        }
        this.errors = remapped;
    },
    removeItemErrors(removedIndex) {
        const remapped = {};
        for (const [key, messages] of Object.entries(this.errors)) {
            const match = key.match(/^items\.(\d+)\.(.+)$/);
            if (!match) {
                remapped[key] = messages;
                continue;
            }

            const index = Number(match[1]);
            if (index === removedIndex) continue;
            remapped[`items.${index > removedIndex ? index - 1 : index}.${match[2]}`] = messages;
        }
        this.errors = remapped;
    },
    reorderPreviewItems(source, target) {
        if (!Array.isArray(this.preview?.items)) return;

        const previewItems = [...this.preview.items].sort((left, right) => Number(left.position) - Number(right.position));
        const [item] = previewItems.splice(source, 1);
        if (!item) return;
        previewItems.splice(target, 0, item);
        this.preview = {
            ...this.preview,
            items: previewItems.map((previewItem, index) => ({ ...previewItem, position: index + 1 })),
        };
    },
    removePreviewItem(index) {
        if (!Array.isArray(this.preview?.items)) return;

        this.preview = {
            ...this.preview,
            items: this.preview.items
                .filter((previewItem) => Number(previewItem.position) !== index + 1)
                .map((previewItem, previewIndex) => ({ ...previewItem, position: previewIndex + 1 })),
        };
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
        this.queuePreview();
    },
    queuePreview(delay = 400) {
        window.clearTimeout(this.previewTimer);
        this.loading = true;
        this.previewTimer = window.setTimeout(() => this.refreshPreview(), delay);
    },
    previewLineTotal(position) {
        const item = this.preview?.items?.find((previewItem) => Number(previewItem.position) === Number(position));

        return item?.line_total_amount;
    },
    previewGrandTotal() {
        return this.preview?.totals?.grand_total;
    },
    async refreshPreview(force = false) {
        if (!this.$refs.form) return;

        window.clearTimeout(this.previewTimer);
        const body = this.previewFormData();
        const signature = JSON.stringify(Array.from(body.entries(), ([key, value]) => [key, String(value)]));
        if (!force && signature === this.lastPreviewSignature) {
            this.loading = false;

            return;
        }

        this.lastPreviewSignature = signature;
        this.previewController?.abort();
        const controller = new AbortController();
        const requestId = ++this.previewRequestId;
        this.previewController = controller;
        this.loading = true;
        this.previewError = '';
        try {
            const response = await fetch(config.previewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body,
                signal: controller.signal,
            });

            if (response.redirected || (response.status >= 300 && response.status < 400)) {
                throw new Error('Relace vypršela nebo je nutné se znovu přihlásit. Obnovte stránku.');
            }

            const contentType = (response.headers.get('content-type') ?? '').toLowerCase();
            const isJson = contentType.includes('application/json') || contentType.includes('+json');
            if (!isJson) {
                if (!response.ok) throw new Error(this.previewHttpError(response.status, {}));

                throw new Error(response.status >= 500
                    ? 'Server nyní nemůže náhled vypočítat. Zkuste to prosím znovu.'
                    : 'Server vrátil neočekávanou odpověď. Obnovte stránku a zkuste to znovu.');
            }

            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error('Server vrátil neplatnou odpověď. Zkuste to prosím znovu.');
            }

            if (!response.ok) throw new Error(this.previewHttpError(response.status, data));
            if (requestId !== this.previewRequestId) return;
            this.preview = data;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            if (requestId !== this.previewRequestId) return;

            this.previewError = error instanceof Error
                ? error.message
                : 'Náhled nyní nelze vypočítat.';
        } finally {
            if (requestId === this.previewRequestId) this.loading = false;
        }
    },
    previewFormData() {
        const body = new FormData(this.$refs.form);
        body.delete('version');
        body.delete('correlation_uuid');
        for (const key of [...body.keys()]) {
            if (key.startsWith('items[')) body.delete(key);
        }
        this.items.forEach((item, index) => {
            const prefix = `items[${index}]`;
            body.append(`${prefix}[position]`, String(index + 1));
            body.append(`${prefix}[description]`, String(item.description ?? ''));
            body.append(`${prefix}[quantity]`, String(item.quantity ?? ''));
            body.append(`${prefix}[unit]`, String(item.unit ?? ''));
            body.append(`${prefix}[unit_price]`, String(item.unit_price ?? ''));
            body.append(`${prefix}[discount_type]`, String(item.discount_type ?? 'none'));
            body.append(`${prefix}[discount_value]`, String(item.discount_value ?? '0'));
            if (config.isVatPayer) {
                body.append(`${prefix}[vat_rate_uuid]`, String(item.vat_rate_uuid ?? ''));
            }
        });

        return body;
    },
    previewHttpError(status, data) {
        if (status === 401 || status === 403) return 'Pro výpočet náhledu nemáte oprávnění nebo vypršela relace.';
        if (status === 419) return 'Relace vypršela. Obnovte stránku a zkuste to znovu.';
        if (status === 422) return Object.values(data?.errors ?? {}).flat()[0]
            ?? 'Náhled nelze vypočítat, dokud neopravíte zadané hodnoty.';
        if (status === 429) return 'Náhled se přepočítává příliš často. Chvíli počkejte a zkuste to znovu.';
        if (status >= 500) return 'Server nyní nemůže náhled vypočítat. Zkuste to prosím znovu.';

        return 'Náhled nyní nelze vypočítat.';
    },
    money(value) {
        return value === null || value === undefined ? '—' : String(value).replace('.', ',');
    },
    lineMoney(value) {
        if (value === null || value === undefined) return '—';

        const [integer, fraction = ''] = String(value).split('.');
        const displayedFraction = fraction.padEnd(2, '0').replace(/0+$/, '').padEnd(2, '0');

        return `${integer},${displayedFraction}`;
    },
}));

window.Alpine = Alpine;

Alpine.start();
