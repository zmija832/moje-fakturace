export function createInvoicePreviewPipeline({
    buildBody,
    send,
    apply,
    fail,
    loading,
    debounceMs = 250,
    setTimer = (callback, delay) => window.setTimeout(callback, delay),
    clearTimer = (timer) => window.clearTimeout(timer),
    createController = () => new AbortController(),
}) {
    let timer = null;
    let controller = null;
    let requestId = 0;

    const refresh = async () => {
        clearTimer(timer);
        timer = null;
        controller?.abort();
        controller = createController();
        const localId = ++requestId;
        const body = buildBody();
        loading(true);

        try {
            const response = await send(body, controller.signal);
            if (localId !== requestId) return false;

            apply(response);

            return true;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return false;
            if (localId !== requestId) return false;

            fail(error);

            return false;
        } finally {
            if (localId === requestId) loading(false);
        }
    };

    return {
        queue(delay = debounceMs) {
            clearTimer(timer);
            timer = setTimer(refresh, delay);
        },
        refresh,
        destroy() {
            clearTimer(timer);
            timer = null;
            controller?.abort();
            controller = null;
            requestId += 1;
            loading(false);
        },
    };
}
