/* REDLINE Technician job actions, 2026-11-20.
 * Save to the existing employees/index.php controller using JSON, then refresh
 * just the affected work-order UI. No page navigation, no browser reload.
 * Progressive enhancement: without JavaScript, normal form POST still works.
 */
(() => {
    'use strict';
    const supported = new Set([
        'save_inspection', 'upload_work_image', 'finish_work_session', 'update_job_status'
    ]);
    const active = new Set();
    let toastTimer;

    function toast(message, failed = false) {
        let node = document.getElementById('technician-ajax-feedback');
        if (!node) {
            node = document.createElement('div');
            node.id = 'technician-ajax-feedback';
            node.setAttribute('aria-live', 'polite');
            node.setAttribute('role', 'status');
            node.style.cssText = [
                'position:fixed', 'bottom:20px', 'right:20px', 'max-width:min(440px,calc(100vw - 40px))',
                'z-index:10000', 'border-radius:12px', 'padding:12px 18px',
                'box-shadow:0 12px 35px rgba(0,0,0,.32)', 'font-size:14px', 'font-weight:600'
            ].join(';');
            document.body.appendChild(node);
        }
        clearTimeout(toastTimer);
        node.textContent = message;
        node.style.backgroundColor = failed ? '#7f1d1d' : '#064e3b';
        node.style.color = '#fff';
        node.style.border = failed ? '1px solid #fca5a5' : '1px solid #6ee7b7';
        node.style.display = 'block';
        node.setAttribute('role', failed ? 'alert' : 'status');
        toastTimer = setTimeout(() => { node.style.display = 'none'; }, failed ? 12000 : 7000);
    }

    function localMessage(form, message, failed = false) {
        let node = form.querySelector('[data-technician-action-result]');
        if (!node) {
            node = document.createElement('p');
            node.dataset.technicianActionResult = '';
            node.className = 'mt-2 text-sm font-semibold';
            node.setAttribute('aria-live', 'polite');
            form.appendChild(node);
        }
        node.textContent = message;
        node.style.color = failed ? '#fda4af' : '#6ee7b7';
        // Ensure contrast inside the cream colored handover panel.
        if (form.closest('#job-handover')) {
            node.style.color = failed ? '#991b1b' : '#065f46';
        }
    }

    async function postJobAction(form) {
        // `form.action` is NOT safe here: a hidden input named "action" can shadow it.
        const destination = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        destination.hash = '';
        const response = await fetch(destination.href, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'follow',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.toLowerCase().includes('application/json')) {
            throw new Error('Server did not return JSON. Verify that employees/index.php was updated and that your login session is active. Check the order before submitting again.');
        }
        let payload;
        try { payload = await response.json(); }
        catch (_) { throw new Error('Invalid JSON response from server. Verify the order before resubmitting.'); }
        if (!payload || typeof payload !== 'object' || !payload.ok || !response.ok) {
            throw new Error(payload && payload.message ? String(payload.message) : `Could not save (HTTP ${response.status}).`);
        }
        return payload;
    }

    async function loadFreshDetails(orderId) {
        const url = new URL(window.location.href);
        url.hash = '';
        url.searchParams.set('view', 'job');
        url.searchParams.set('id', orderId);
        const response = await fetch(url.href, {
            credentials: 'same-origin', cache: 'no-store',
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Could not load the latest job details from the server.');
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const details = doc.getElementById('technician-job-details');
        if (!details || details.dataset.orderId !== String(orderId)) {
            throw new Error('Could not verify the current job details. Your session might have expired.');
        }
        return doc;
    }

    function replaceElement(id, fromDoc) {
        const current = document.getElementById(id);
        const next = fromDoc.getElementById(id);
        if (!current || !next) throw new Error(`Could not refresh ${id}.`);
        current.replaceWith(document.importNode(next, true));
    }

    function preservePosition(anchorId, update) {
        const oldAnchor = document.getElementById(anchorId);
        const scrollBox = oldAnchor?.closest('.employee-content');
        const topBefore = oldAnchor?.getBoundingClientRect().top;
        update();
        const newAnchor = document.getElementById(anchorId);
        // Internal scrolling is on <main class="employee-content">, not the window.
        if (scrollBox && topBefore !== undefined && newAnchor) {
            const offset = newAnchor.getBoundingClientRect().top - topBefore;
            scrollBox.scrollTop += offset;
        }
    }

    function updateAfterSave(action, fromDoc) {
        if (action === 'upload_work_image') {
            preservePosition('job-photos', () => replaceElement('job-photos-gallery', fromDoc));
            return;
        }
        if (action === 'update_job_status') {
            const current = document.getElementById('technician-job-details');
            const refreshed = fromDoc.getElementById('technician-job-details');
            // On completion, every editing control must become read-only.
            if (refreshed?.dataset.status === 'completed' || refreshed?.dataset.status === 'delivered') {
                preservePosition('job-next-action', () => replaceElement('technician-job-details', fromDoc));
                return;
            }
            // All other stage changes update only progress-related components;
            // unrelated unsaved inspection fields and labor forms stay untouched.
            preservePosition('job-next-action', () => {
                ['job-overview', 'job-progress', 'job-current-task',
                    'job-status-timeline', 'job-next-action'].forEach(id => replaceElement(id, fromDoc));
            });
            return;
        }
        if (action === 'finish_work_session') {
            // Session has closed: reload the detail fragment so no edit form
            // remains operable for the previous technician.
            preservePosition('job-next-action', () => replaceElement('technician-job-details', fromDoc));
        }
    }

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = form.getAttribute('data-technician-ajax');
        if (!supported.has(action)) return;
        // Respect existing inline confirm() for handover and final completion.
        if (event.defaultPrevented) return;
        if (typeof window.fetch !== 'function' || typeof FormData !== 'function') return;
        event.preventDefault();
        if (active.size > 0) {
            toast('Another job action is being saved. Finish that action before submitting the next one.', true);
            return;
        }

        const job = document.getElementById('technician-job-details');
        const orderId = job?.dataset.orderId;
        if (!orderId) {
            toast('Could not determine the repair order. Reload this page before trying again.', true);
            return;
        }
        if (action === 'upload_work_image') {
            const file = form.querySelector('input[name="work_image"]')?.files?.[0];
            if (!file) { toast('Choose an image before uploading.', true); return; }
            if (file.size > 5 * 1024 * 1024) {
                toast('The maximum image size is 5 MB.', true);
                return;
            }
        }

        active.add(action);
        const button = event.submitter || form.querySelector('button[type="submit"],button:not([type])');
        const originalText = button?.textContent;
        if (button) { button.disabled = true; button.textContent = 'Saving…'; }
        form.setAttribute('aria-busy', 'true');
        let saved = false;
        let refreshFailedAfterSave = false;
        try {
            const result = await postJobAction(form);
            saved = true;
            if (action !== 'save_inspection') {
                const latest = await loadFreshDetails(orderId);
                updateAfterSave(action, latest);
            }
            if (action === 'upload_work_image' && form.isConnected) {
                const field = form.querySelector('input[name="work_image"]');
                if (field) field.value = '';
            }
            if (form.isConnected) localMessage(form, result.message || 'Saved.');
            toast(result.message || 'Saved successfully.');
        } catch (error) {
            refreshFailedAfterSave = saved;
            const message = saved
                ? 'Saved successfully, but the updated view could not be loaded. Refresh once to see the latest data. Do not submit the same action again.'
                : (error?.message || 'Could not confirm the save. Check the work order before retrying.');
            if (form.isConnected) localMessage(form, message, !saved);
            if (!saved && form.isConnected) {
                window.REDLINETechnicianValidation?.notifyServerError(form, message);
            }
            toast(message, !saved);
        } finally {
            active.delete(action);
            if (form.isConnected) form.removeAttribute('aria-busy');
            if (button && button.isConnected) {
                // A confirmed save must not be submitted again merely because the
                // subsequent read/refresh failed (especially image uploads).
                button.disabled = refreshFailedAfterSave;
                button.textContent = originalText;
            }
        }
    });
})();
