/* REDLINE Shifts & Payroll — stable-position AJAX approvals, no page reload. */
(() => {
  'use strict';

  const table = document.querySelector('#shift-registrations');
  const feedback = document.querySelector('#shift-ajax-feedback');
  if (!table || !table.tBodies.length || !feedback) return;

  let requestInFlight = false;

  function showFeedback(message, isError) {
    feedback.hidden = false;
    feedback.className = isError ? 'tc-error' : 'tc-success';
    feedback.textContent = message;
    feedback.setAttribute('role', isError ? 'alert' : 'status');
  }

  // Capture the page scroll AND any nested scroller (useful if the admin layout changes).
  function captureScroll() {
    const scrollRoot = document.scrollingElement || document.documentElement;
    const positions = [{ element: scrollRoot, top: scrollRoot.scrollTop, left: scrollRoot.scrollLeft }];
    for (let el = table.parentElement; el; el = el.parentElement) {
      if (el !== scrollRoot && (el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth)) {
        positions.push({ element: el, top: el.scrollTop, left: el.scrollLeft });
      }
    }
    return positions;
  }

  function restoreScroll(positions) {
    for (const { element, top, left } of positions) {
      if (!element.isConnected) continue;
      element.scrollTop = top;
      element.scrollLeft = left;
    }
  }

  function updatedRowsFromHtml(html) {
    // This HTML comes from the authenticated PHP endpoint, which escapes row values.
    const detachedTable = document.createElement('table');
    detachedTable.innerHTML = '<tbody>' + html + '</tbody>';
    return [...detachedTable.tBodies[0].rows];
  }

  function getRowById(id) {
    return [...table.tBodies[0].rows].find((row) => row.dataset.shiftId === id);
  }

  function updateChip(existingCell, freshCell) {
    if (!existingCell || !freshCell) return;
    const oldChip = existingCell.querySelector('.tc-chip');
    const newChip = freshCell.querySelector('.tc-chip');
    if (oldChip && newChip) {
      oldChip.className = newChip.className;
      oldChip.textContent = newChip.textContent;
    } else {
      existingCell.innerHTML = freshCell.innerHTML;
    }
  }

  function updateExistingRow(existingRow, freshRow, moveFocus) {
    // Keep the SAME <tr> in the SAME spot. An approved row must not jump down
    // into the approved group until the user explicitly reloads the page.
    updateChip(existingRow.cells[3], freshRow.cells[3]);
    updateChip(existingRow.cells[4], freshRow.cells[4]);

    if (moveFocus) {
      // The clicked button will be removed with its Actions cell. Move focus
      // first, without asking the browser to scroll to the new focus target.
      const status = existingRow.cells[3]?.querySelector('.tc-chip');
      if (status) {
        status.tabIndex = -1;
        status.focus({ preventScroll: true });
      }
    }
    if (existingRow.cells[5] && freshRow.cells[5]) {
      existingRow.cells[5].innerHTML = freshRow.cells[5].innerHTML;
    }
  }

  function applyUpdatedRows(serverRows, selectedId) {
    const tbody = table.tBodies[0];
    const incomingIds = new Set();
    for (const serverRow of serverRows) {
      const id = serverRow.dataset.shiftId;
      if (!id) continue;
      incomingIds.add(id);
      const existing = getRowById(id);
      if (existing) {
        updateExistingRow(existing, serverRow, id === selectedId);
      } else {
        // New admin-created shift: add without moving/reordering existing rows.
        if (tbody.rows.length === 1 && !tbody.rows[0].dataset.shiftId) {
          tbody.replaceChildren();
        }
        tbody.appendChild(serverRow);
      }
    }
    // Keep existing rows that aren't in this limited response, rather than
    // removing them and unexpectedly changing the page height during approval.
  }

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) ||
        !form.matches('form[data-ajax-shift], form[data-ajax-register]')) return;

    event.preventDefault();
    if (requestInFlight) return;
    requestInFlight = true;

    const savedScroll = captureScroll();
    const buttons = [...form.querySelectorAll('button[type="submit"]')];
    const originalButtons = buttons.map((button) => ({ button, text: button.textContent }));
    const allButtons = [...document.querySelectorAll('form[data-ajax-shift] button[type="submit"], form[data-ajax-register] button[type="submit"]')];
    const selectedId = form.closest('tr')?.dataset.shiftId || '';

    form.dataset.saving = 'true';
    form.setAttribute('aria-busy', 'true');
    allButtons.forEach((button) => { button.disabled = true; });
    buttons.forEach((button) => { button.textContent = 'Saving…'; });

    let succeeded = false;
    try {
      const payload = new FormData(form);
      payload.set('shift_ajax', '1');
      // Forms have an <input name="action">. In browsers it can shadow
      // HTMLFormElement.action, turning form.action into an input element.
      // Fetching that object would request /admin/[object HTMLInputElement] (404).
      // Read the literal action ATTRIBUTE, and fall back to the actual page path.
      const endpoint = new URL(form.getAttribute('action') || window.location.pathname, window.location.href);
      const response = await fetch(endpoint.href, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      // Older PHP versions or redirects can deliver an entire login/admin page.
      // Never insert that HTML into the current page.
      const finalPath = new URL(response.url || window.location.href, window.location.href).pathname.toLowerCase();
      if (response.redirected || /\/(?:auth\/)?login\.php$/.test(finalPath)) {
        throw new Error('Your session expired or the server redirected (HTTP ' + response.status + '). Sign in again and retry.');
      }
      if (!contentType.includes('application/json')) {
        let diagnostic;
        if (response.status >= 500) {
          diagnostic = 'Server-side PHP error (HTTP ' + response.status + '). Check the hosting PHP error log.';
        } else if (response.status === 404) {
          diagnostic = 'HTTP 404 at ' + endpoint.pathname + '. Check the endpoint and server rules for POST requests.';
        } else {
          diagnostic = 'Server returned HTML instead of JSON (HTTP ' + response.status + '). The deployed admin/technician_management.php may be outdated. Check ?ajax_health=1.';
        }
        throw new Error(diagnostic);
      }
      let result;
      try {
        result = await response.json();
      } catch (_parseError) {
        throw new Error('Invalid JSON returned by PHP (HTTP ' + response.status + '). Check warnings and errors in the PHP error log.');
      }
      if (!response.ok || !result.ok) {
        throw new Error(result.error || 'Unable to update the shift.');
      }
      if (typeof result.rows_html !== 'string' || !result.stats) {
        throw new Error('Server response is missing updated shift data.');
      }

      applyUpdatedRows(updatedRowsFromHtml(result.rows_html), selectedId);
      const pending = document.querySelector('#shifts-pending-count');
      const approvedToday = document.querySelector('#shifts-approved-today-count');
      if (pending) pending.textContent = String(result.stats.pending);
      if (approvedToday) approvedToday.textContent = String(result.stats.approved_today);
      showFeedback(result.message || 'Shift updated successfully.', false);
      succeeded = true;
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'Network error. Please try again.', true);
    } finally {
      // Some form controls are removed on success; touching their detached nodes
      // is harmless, but only re-enable controls that remain in the document.
      originalButtons.forEach(({ button, text }) => { button.textContent = text; });
      allButtons.forEach((button) => { if (button.isConnected) button.disabled = false; });
      if (form.isConnected) {
        form.removeAttribute('aria-busy');
        delete form.dataset.saving;
      }
      requestInFlight = false;

      // Disabling/replacing the focused Approve button can trigger browser scroll
      // anchoring. Explicitly restore the original viewport after the DOM update.
      restoreScroll(savedScroll);
      if (succeeded) {
        requestAnimationFrame(() => {
          restoreScroll(savedScroll);
          requestAnimationFrame(() => restoreScroll(savedScroll));
        });
      }
    }
  });
})();
