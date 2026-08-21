import Alpine from 'alpinejs';
import { applyInvoiceCatalogSelection } from './invoice-catalog-selection';
import { buildInvoicePreviewFormData } from './invoice-preview-payload';
import { applyInvoicePreviewResponse, setInvoiceItemsPreviewUpdating } from './invoice-preview-state';

async function lookupClientInAres(url, csrf, ico) {
    const normalized = String(ico ?? '').replace(/\s+/g, '');
    if (!/^\d{8}$/.test(normalized)) {
        const error = new Error('IČO musí obsahovat přesně 8 číslic.');
        error.validation = true;
        throw error;
    }
    const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ ico: normalized }) });
    const data = await response.json().catch(() => ({}));
    if (response.status === 422) {
        const error = new Error(data.errors?.ico?.[0] ?? 'Zadané IČO není platné.');
        error.validation = true;
        throw error;
    }
    if (!response.ok || !data.subject) throw new Error(data.message ?? 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.');
    return { ...data, ico: normalized };
}

Alpine.data('aresClientForm', (config) => ({
    loading: false, message: '', warning: '', error: '',
    async lookup() {
        if (this.loading) return;
        this.loading = true; this.message = ''; this.warning = ''; this.error = '';
        try {
            const result = await lookupClientInAres(config.url, config.csrf, this.$refs.form.elements.registration_number.value);
            for (const field of ['company_name', 'registration_number', 'tax_id', 'street', 'city', 'postal_code', 'country_code']) {
                const input = this.$refs.form.elements.namedItem(field);
                const value = result.subject[field];
                if (input && typeof value === 'string' && value !== '') input.value = value;
            }
            this.message = 'Údaje byly načteny z ARES. Před uložením je můžete upravit.';
            this.warning = Array.isArray(result.warnings) ? result.warnings.join(' ') : '';
        } catch (error) {
            this.error = error instanceof Error ? error.message : 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.';
        } finally { this.loading = false; }
    },
}));

Alpine.data('invoiceEditor', (config) => ({
    items: config.items,
    currency: config.currency,
    paymentMethod: config.paymentMethod,
    defaultBankAccounts: config.defaultBankAccounts ?? {},
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
            item._catalogResults = [];
            item._catalogRequest = 0;
            item._previewLineTotal = null;
            item._previewUpdating = false;
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
            _catalogResults: [], _catalogRequest: 0,
            _previewLineTotal: null, _previewUpdating: false,
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
    fieldError(index, field) {
        return this.errors[`items.${index}.${field}`]?.[0] ?? '';
    },
    async searchCatalog(index) {
        const item = this.items[index];
        if (!item || !config.catalogSearchUrl) return;
        const requestId = ++item._catalogRequest;
        const url = new URL(config.catalogSearchUrl, window.location.origin);
        url.searchParams.set('currency', this.currency);
        url.searchParams.set('q', item.description ?? '');
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (requestId === item._catalogRequest) item._catalogResults = response.ok && Array.isArray(data.items) ? data.items : [];
        } catch {
            if (requestId === item._catalogRequest) item._catalogResults = [];
        }
    },
    applyCatalogItem(index, catalogItem) {
        const item = this.items[index];
        if (!item || catalogItem.currency !== this.currency) return;

        const selectedItem = applyInvoiceCatalogSelection(item, catalogItem, config.isVatPayer);
        if (!selectedItem) return;

        selectedItem._catalogRequest = (item._catalogRequest ?? 0) + 1;
        selectedItem._catalogResults = [];
        this.items.splice(index, 1, selectedItem);
        this.$nextTick(() => this.queuePreview(0, true));
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

        this.quickClientErrors = { ...this.quickClientErrors, registration_number: [] };
        this.aresLoading = true;

        try {
            const data = await lookupClientInAres(config.aresLookupUrl, config.csrf, ico);
            registrationInput.value = data.ico;

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
            if (error?.validation) {
                this.quickClientErrors = { ...this.quickClientErrors, registration_number: [error.message] };

                return;
            }
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
        if (option.dataset.currency) {
            this.currency = option.dataset.currency;
            this.$nextTick(() => this.applyDefaultBankAccount());
        }
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
    applyDefaultBankAccount() {
        this.items.forEach((item) => { item._catalogRequest++; item._catalogResults = []; });
        const select = this.$refs.bankAccountSelect;
        if (!select) return;

        const defaultUuid = this.defaultBankAccounts[this.currency] ?? '';
        const option = Array.from(select.options).find((candidate) => candidate.value === defaultUuid && !candidate.disabled);
        select.value = option?.value ?? '';
    },
    queuePreview(delay = 400, force = false) {
        window.clearTimeout(this.previewTimer);
        this.previewController?.abort();
        this.previewController = null;
        this.previewRequestId += 1;
        this.loading = true;
        setInvoiceItemsPreviewUpdating(this.items, true);
        this.previewTimer = window.setTimeout(() => this.refreshPreview(force), delay);
    },
    previewLineTotalDisplay(item) {
        return item?._previewLineTotal ?? null;
    },
    previewGrandTotalDisplay() {
        return this.preview?.display?.totals?.grand_total;
    },
    previewCurrencyDisplay() {
        return this.preview?.display?.currency ?? this.currency;
    },
    async refreshPreview(force = false) {
        if (!this.$refs.form) return;

        window.clearTimeout(this.previewTimer);
        const body = this.previewFormData();
        const signature = JSON.stringify(Array.from(body.entries(), ([key, value]) => [key, String(value)]));
        if (!force && signature === this.lastPreviewSignature) {
            this.loading = false;
            setInvoiceItemsPreviewUpdating(this.items, false);

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
            if (!applyInvoicePreviewResponse(this.items, data, requestId, this.previewRequestId)) return;
            this.preview = data;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            if (requestId !== this.previewRequestId) return;

            this.previewError = error instanceof Error
                ? error.message
                : 'Náhled nyní nelze vypočítat.';
        } finally {
            if (requestId === this.previewRequestId) {
                this.loading = false;
                setInvoiceItemsPreviewUpdating(this.items, false);
            }
        }
    },
    previewFormData() {
        return buildInvoicePreviewFormData(this.$refs.form, this.items, config.isVatPayer);
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
}));

window.Alpine = Alpine;

Alpine.start();

const recurringItems = document.getElementById('recurring-items');
const recurringItemTemplate = document.getElementById('recurring-item-template');
const recurringAddItem = document.getElementById('recurring-add-item');

if (recurringItems && recurringItemTemplate instanceof HTMLTemplateElement && recurringAddItem) {
    const bindRemove = (button) => button?.addEventListener('click', () => {
        if (recurringItems.querySelectorAll('.recurring-item').length > 1) {
            button.closest('.recurring-item')?.remove();
        }
    });

    recurringItems.querySelectorAll('.recurring-remove').forEach(bindRemove);
    recurringAddItem.addEventListener('click', () => {
        const indexes = Array.from(recurringItems.querySelectorAll('[name^="items["]'))
            .map((element) => Number(element.name.match(/^items\[(\d+)]/)?.[1] ?? -1));
        const index = Math.max(0, ...indexes) + 1;
        const fragment = recurringItemTemplate.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((element) => {
            element.name = element.name.replace('__INDEX__', String(index));
        });
        bindRemove(fragment.querySelector('.recurring-remove'));
        recurringItems.appendChild(fragment);
    });
}
