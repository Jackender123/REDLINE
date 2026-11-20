/* Submit technician part requests without navigating away from unfinished work. */
(() => {
    'use strict';
    const form = document.getElementById('part-request-form');
    if (!form || typeof window.fetch !== 'function') return;
    const list = document.getElementById('job-part-requests');
    const status = document.getElementById('part-request-status');
    const button = form.querySelector('button[type="submit"]');
    const label = button.querySelector('[data-part-request-label]');
    const originalLabel = label.textContent;
    let submitting = false;

    const showMessage = (message, failed = false) => {
        status.textContent = message;
        status.hidden = false;
        status.setAttribute('role', failed ? 'alert' : 'status');
    };

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || !form.reportValidity()) return;
        submitting = true;
        button.disabled = true;
        label.textContent = 'Submitting…';
        form.setAttribute('aria-busy', 'true');
        showMessage('Submitting part request…');
        try {
            // `name="action"` is also a hidden form input, which can shadow
            // HTMLFormElement.action and make fetch() request an invalid URL.
            const endpoint = form.getAttribute('action') || (window.location.pathname + window.location.search);
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Could not confirm the request. Check the request list before submitting again.');
            }
            const result = await response.json();
            if (!response.ok || !result.ok) {
                showMessage(result.message || 'The request could not be submitted.', true);
                window.REDLINETechnicianValidation?.notifyServerError(form, result.message || 'The request could not be submitted.');
                return;
            }
            const request = result.request;
            if (!request || !request.request_code) {
                throw new Error('Could not confirm the request. Check the request list before submitting again.');
            }
            const row = document.createElement('tr');
            const values = [request.request_code, `${request.part_code} · ${request.part_name}`,
                request.stock_quantity, request.quantity, request.status];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                cell.className = index === 0 ? 'py-3 font-semibold' : 'py-3';
                cell.textContent = String(value);
                row.appendChild(cell);
            });
            list.querySelector('[data-part-requests-empty]')?.remove();
            list.prepend(row);
            form.reset();
            showMessage(result.message);
        } catch (error) {
            const notice = error?.message || 'Could not confirm the request. Check the request list before submitting again.';
            showMessage(notice, true);
            window.REDLINETechnicianValidation?.notifyServerError(form, notice);
        } finally {
            submitting = false;
            button.disabled = false;
            label.textContent = originalLabel;
            form.removeAttribute('aria-busy');
        }
    });
})();
