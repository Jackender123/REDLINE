/* REDLINE technician form validation (20261120-validation-v1).
 * Incomplete forms never navigate: point at the first missing/invalid field,
 * preserve all entered values, and scroll the inner technician workspace.
 * Works with labor, parts, inspection, photos, handover and job status.
 */
(() => {
    'use strict';

    const FORM_SELECTOR = '#technician-job-details #repair-item-form, ' +
        '#technician-job-details #part-request-form, ' +
        '#technician-job-details form[data-technician-ajax]';
    const names = {
        item_name: 'work description', unit_price: 'labor unit price',
        quantity: 'quantity', part_id: 'requested part',
        work_image: 'before/after repair photo', image_type: 'photo type',
        handover_completed_work: 'completed work',
        handover_remaining_work: 'remaining handover work',
        job_status: 'next progress stage',
        confirm_completion: 'final inspection confirmation',
        hybrid_voltage: 'battery voltage', hybrid_temperature: 'battery temperature'
    };
    let nextId = 0;

    const formFor = node => node?.closest?.('form')?.matches(FORM_SELECTOR)
        ? node.closest('form') : null;

    function installStyles() {
        if (document.getElementById('rl-technician-validation-styles')) return;
        const sheet = document.createElement('style');
        sheet.id = 'rl-technician-validation-styles';
        sheet.textContent = `
            #technician-job-details .rl-tech-invalid {
                border-color: #e11d48 !important;
                outline: 2px solid #fb7185 !important;
                outline-offset: 2px;
            }
            #technician-job-details .rl-tech-field-error {
                display:block; color:#be123c; font-size:12px; font-weight:700;
                margin-top:6px; line-height:1.5; flex-basis:100%;
            }
            #technician-job-details .rl-tech-form-banner {
                grid-column:1/-1; flex-basis:100%; background:#fff1f2;
                color:#9f1239; border:1px solid #fda4af; border-radius:10px;
                padding:10px 12px; font-size:13px; font-weight:700;
            }
        `;
        document.head.appendChild(sheet);
    }

    function activate(formsRoot = document) {
        // Set noValidate only when JavaScript is active. A browser without JS
        // retains its native required-field validation.
        formsRoot.querySelectorAll(FORM_SELECTOR).forEach(form => { form.noValidate = true; });
        if (formsRoot instanceof HTMLFormElement && formsRoot.matches(FORM_SELECTOR)) {
            formsRoot.noValidate = true;
        }
    }

    function describe(control) {
        if (names[control.name]) return names[control.name];
        const ownLabel = control.labels && control.labels[0];
        if (ownLabel) {
            const labelText = Array.from(ownLabel.childNodes)
                .filter(node => node.nodeType === Node.TEXT_NODE)
                .map(node => node.textContent.trim()).join(' ').trim();
            if (labelText) return labelText;
        }
        return control.getAttribute('placeholder') || control.name || 'this field';
    }

    function issue(control) {
        if (!control || !control.willValidate || control.disabled) return null;
        const name = describe(control);
        if (control.type === 'file') {
            const file = control.files && control.files[0];
            if (control.required && !file) return `Select ${name} before uploading.`;
            if (file && file.size > 5 * 1024 * 1024) return 'Image exceeds 5 MB. Choose a smaller file.';
            if (file && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                return 'Only JPG, PNG, and WebP photos are accepted.';
            }
        }
        if ((control.tagName === 'INPUT' || control.tagName === 'TEXTAREA')
            && control.type !== 'checkbox' && control.type !== 'radio' && control.type !== 'file'
            && control.required && !control.value.trim()) {
            return `Enter ${name}.`;
        }
        if (control.validity.valueMissing) {
            return control.type === 'checkbox'
                ? 'Confirm that the final inspection is complete before closing the order.'
                : (control.tagName === 'SELECT' ? `Select ${name}.` : `Enter ${name}.`);
        }
        // A programmatically populated textarea may not trigger native tooShort.
        const min = Number(control.getAttribute('minlength') || 0);
        if (min > 0 && 'value' in control && control.value.trim().length < min) {
            return `The content for ${name} must contain at least ${min} characters.`;
        }
        if (control.validity.badInput || control.validity.typeMismatch || control.validity.stepMismatch) {
            return `Value ${name} is invalid. Please check it.`;
        }
        if (control.validity.rangeUnderflow) return `${name}: value must be at least ${control.min}.`;
        if (control.validity.rangeOverflow) return `${name}: value must not exceed ${control.max}.`;
        if (control.validity.patternMismatch || control.validity.tooShort || control.validity.tooLong
            || control.validity.customError || !control.validity.valid) {
            return control.validationMessage || `Please check ${name}.`;
        }
        return null;
    }

    function firstInvalid(form) {
        return Array.from(form.elements).find(control => issue(control) !== null) || null;
    }

    function clearField(field) {
        field.classList.remove('rl-tech-invalid');
        field.removeAttribute('aria-invalid');
        const message = field.parentElement?.querySelector(`[data-rl-for="${field.dataset.rlFieldId || ''}"]`);
        if (message) message.remove();
        const id = field.dataset.rlHintId;
        if (id) {
            field.setAttribute('aria-describedby', (field.getAttribute('aria-describedby') || '')
                .split(/\s+/).filter(value => value && value !== id).join(' '));
            field.removeAttribute('data-rl-hint-id');
        }
    }

    function clearBanner(form) {
        form.querySelector('[data-rl-validation-summary]')?.remove();
    }

    function expandParents(field) {
        let parent = field.parentElement;
        while (parent) {
            if (parent.tagName === 'DETAILS' && !parent.open) parent.open = true;
            if (parent.hidden && (parent.id === 'job-completion-confirm')) parent.hidden = false;
            parent = parent.parentElement;
        }
    }

    function navigate(field) {
        expandParents(field);
        try { field.focus({ preventScroll: true }); } catch (_) { field.focus(); }
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
        try { field.scrollIntoView({ behavior: reduced ? 'instant' : 'smooth', block: 'center', inline: 'nearest' }); }
        catch (_) { field.scrollIntoView(); }
    }

    function showFieldError(form, field, message) {
        installStyles();
        clearField(field);
        const identity = field.dataset.rlFieldId || `rl-error-field-${++nextId}`;
        field.dataset.rlFieldId = identity;
        field.classList.add('rl-tech-invalid');
        field.setAttribute('aria-invalid', 'true');
        const hint = document.createElement('small');
        hint.className = 'rl-tech-field-error';
        hint.id = `${identity}-hint`;
        hint.dataset.rlFor = identity;
        hint.setAttribute('role', 'alert');
        hint.textContent = message;
        field.insertAdjacentElement('afterend', hint);
        field.dataset.rlHintId = hint.id;
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), hint.id].filter(Boolean).join(' '));
        showBanner(form, message);
        navigate(field);
    }

    function showBanner(form, message) {
        installStyles();
        let banner = form.querySelector('[data-rl-validation-summary]');
        if (!banner) {
            banner = document.createElement('p');
            banner.className = 'rl-tech-form-banner';
            banner.dataset.rlValidationSummary = '';
            banner.setAttribute('role', 'alert');
            form.prepend(banner);
        }
        banner.textContent = `Unable to save: ${message} Your entered data has been preserved.`;
    }

    function notifyServerError(form, message) {
        if (!form || !form.matches(FORM_SELECTOR)) return;
        const messageText = String(message || 'Unable to save. Please try again.');
        const target = /pending|approved part requests|inventory/i.test(messageText)
            ? document.getElementById('job-parts') : null;
        if (target) {
            let alert = document.getElementById('rl-tech-parts-warning');
            if (!alert) {
                alert = document.createElement('p');
                alert.id = 'rl-tech-parts-warning';
                alert.className = 'rl-tech-form-banner mt-3';
                target.prepend(alert);
            }
            alert.setAttribute('role', 'alert');
            alert.textContent = messageText;
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        let field = null;
        if (/battery voltage|temperature/i.test(messageText)) {
            field = form.querySelector('[name="hybrid_voltage"], [name="hybrid_temperature"]');
        } else if (/confirm.*final inspection/i.test(messageText)) {
            field = form.querySelector('[name="confirm_completion"]');
        } else if (/invalid.*status|workflow transition/i.test(messageText)) {
            field = form.querySelector('[name="job_status"]');
        } else if (/image upload|image size|jpg|jpeg|png|webp/i.test(messageText)) {
            field = form.querySelector('[name="work_image"]');
        } else if (/quantity/i.test(messageText)) {
            field = form.querySelector('[name="quantity"]');
        } else if (/price|unit price|labor charge/i.test(messageText)) {
            field = form.querySelector('[name="unit_price"]');
        }
        if (field) showFieldError(form, field, messageText);
        else {
            showBanner(form, messageText);
            const banner = form.querySelector('[data-rl-validation-summary]');
            banner?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // Initialize on load and on DOM refreshes after job-status/handover saves.
    activate();
    const job = document.getElementById('technician-job-details');
    if (job && typeof MutationObserver === 'function') {
        new MutationObserver(() => activate()).observe(job.parentElement || job, { childList: true, subtree: true });
    }

    // Capture before inline onsubmit=confirm and before the AJAX listeners.
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches(FORM_SELECTOR)) return;
        const field = firstInvalid(form);
        if (!field) { clearBanner(form); return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        showFieldError(form, field, issue(field));
    }, true);

    const handleEditedField = event => {
        const field = event.target;
        if (!field?.classList?.contains('rl-tech-invalid')) return;
        const form = formFor(field);
        if (!form || issue(field)) return;
        clearField(field);
        if (!firstInvalid(form)) clearBanner(form);
    };
    document.addEventListener('input', handleEditedField, true);
    document.addEventListener('change', handleEditedField, true);

    window.REDLINETechnicianValidation = Object.freeze({ notifyServerError });
})();
