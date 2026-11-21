// Repair cost review: update the action immediately after confirmed approval.
// Loaded as a separate file to avoid inline JavaScript leaking into the page UI.
(() => {
    'use strict';
    const form = document.getElementById('cost-review-form');
    if (!form) return; // Already approved: the server renders View Invoice.

    const type = form.querySelector('[name="review_type"]');
    const reason = form.querySelector('[name="review_reason"]');
    const hint = document.getElementById('review-reason-required');
    const feedback = document.getElementById('cost-review-feedback');
    const button = document.getElementById('cost-review-submit');
    const total = Number(form.dataset.reviewTotal);
    const defaultLabel = button.textContent;

    function show(message, success) {
        feedback.textContent = message;
        feedback.className = 'rounded-lg border px-3 py-2 text-sm ' + (success
            ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-100'
            : 'border-amber-400/40 bg-amber-400/10 text-amber-100');
    }

    function syncType() {
        const zero = type.value === 'zero';
        reason.required = zero;
        reason.minLength = zero ? 10 : 0;
        hint.textContent = zero
            ? '(required: 10–500 characters for zero-cost/warranty)'
            : '(optional for Standard service)';
        if (zero && total > 0) {
            show('This repair has billable costs. Select Standard service instead.', false);
        } else if (!zero && total <= 0) {
            show('A Standard approval needs priced labor or parts. Select Zero-cost / warranty and provide a reason.', false);
        } else {
            feedback.classList.add('hidden');
        }
    }

    type.addEventListener('change', syncType);
    syncType();

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !form.reportValidity()) return;
        if (type.value === 'standard' && total <= 0) {
            show('No billable costs were recorded. Choose Zero-cost / warranty with a reason, or reopen the order and add charges.', false);
            return;
        }
        if (type.value === 'zero' && total > 0) {
            show('Zero-cost approval is only allowed when billable costs equal $0.00.', false);
            return;
        }

        button.disabled = true;
        button.textContent = 'Approving…';
        let approved = false;
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' },
                body: new FormData(form)
            });
            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (_) {
                throw new Error('Unexpected server response (HTTP ' + response.status + '). Reload the order and check its status.');
            }
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'Cost approval failed.');
            }

            // Server has confirmed and saved the approval. Do not reload the iframe.
            approved = true;
            const orderId = form.elements['repair_order_id'].value;
            const invoiceLink = document.createElement('a');
            invoiceLink.href = new URL('../invoices/list.php?create_from_ro=' + encodeURIComponent(orderId), window.location.href).href;
            invoiceLink.className = 'inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl px-6 py-3 font-semibold transition';
            invoiceLink.textContent = 'View Invoice';

            const invoiceHint = document.createElement('p');
            invoiceHint.className = 'text-xs text-slate-300';
            invoiceHint.textContent = 'No invoice has been issued yet. Continue to the invoice creation form.';
            form.replaceWith(invoiceLink, invoiceHint);

            const state = document.getElementById('cost-review-state');
            if (state) {
                state.textContent = 'Costs approved · ' + (type.value === 'zero' ? 'zero-cost / warranty' : 'standard') +
                    ' · $' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
                    '. You may now create an invoice.';
                state.className = 'text-emerald-200 font-semibold';
            }
            document.getElementById('cost-zero-guidance')?.remove();
        } catch (error) {
            show(error.message || 'Network error: approval has not been confirmed.', false);
        } finally {
            if (!approved) {
                button.disabled = false;
                button.textContent = defaultLabel;
            }
        }
    });
})();
