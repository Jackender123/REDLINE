/* REDLINE Inventory AJAX v1.1 — fix duplicated nested Inventory Filters.
 * Compatible with existing 20261120-inventory-ajax-v1 PHP response marker.
 * Progressive enhancement for products/list.php.
 * Native GET/POST still works if JS is disabled; no database schema changes.
 */
(() => {
  'use strict';
  const main = document.getElementById('inventory-main');
  if (!main || !document.querySelector('meta[name="redline-inventory-ajax"]')) return;

  const build = '20261120-inventory-ajax-v1';
  const pagePath = window.location.pathname;
  const validPostActions = new Set(['add_part', 'restock', 'stock_adjust', 'update_part']);
  const toast = document.createElement('div');
  toast.id = 'inventory-ajax-feedback';
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  toast.style.cssText = 'position:fixed;bottom:20px;right:20px;max-width:min(460px,calc(100vw - 32px));padding:14px 18px;border-radius:12px;background:#13263b;color:#f8fafc;border:1px solid #44566b;box-shadow:0 10px 34px #0008;z-index:100000;font:600 14px/1.45 Barlow,Arial,sans-serif;display:none;';
  document.body.append(toast);
  let navSequence = 0;
  let busyPost = false;

  const showMessage = (message, error = false) => {
    toast.textContent = message;
    toast.style.display = 'block';
    toast.style.borderColor = error ? '#ff6a7c' : '#2aa67d';
    toast.setAttribute('role', error ? 'alert' : 'status');
  };
  const hideMessage = () => { toast.style.display = 'none'; };
  const isPageUrl = url => url.origin === location.origin && url.pathname === pagePath;
  const currentUrl = () => new URL(window.location.href);
  const captureScroll = () => ({ y: window.scrollY, left: main.scrollLeft, top: main.scrollTop });
  const restoreScroll = s => {
    // Avoid scrollIntoView on success: it can scroll the entire page to the top.
    main.scrollTop = s.top;
    main.scrollLeft = s.left;
    window.scrollTo({ top: s.y, left: window.scrollX, behavior: 'instant' });
    requestAnimationFrame(() => { main.scrollTop = s.top; main.scrollLeft = s.left; });
  };
  const formAction = form => new URL(form.getAttribute('action') || 'list.php', window.location.href);
  const firstInvalid = form => Array.from(form.querySelectorAll('input,select,textarea')).find(el => el.willValidate && !el.checkValidity());
  const promptInvalid = form => {
    const input = firstInvalid(form);
    if (!input) return false;
    const details = input.closest('details');
    if (details) details.open = true;
    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    input.focus({ preventScroll: true });
    input.reportValidity();
    showMessage('Vui lòng kiểm tra ô được đánh dấu và bổ sung thông tin bắt buộc.', true);
    return true;
  };
  const clearInlineError = form => {
    form.querySelector('[data-inventory-form-error]')?.remove();
  };
  const inlineError = (form, message) => {
    clearInlineError(form);
    const alert = document.createElement('p');
    alert.dataset.inventoryFormError = '1';
    alert.setAttribute('role', 'alert');
    alert.style.cssText = 'grid-column:1/-1;margin:8px 0 0;padding:10px 12px;border:1px solid #ee5a75;border-radius:9px;background:#422131;color:#fff;font-size:13px;';
    alert.textContent = message;
    form.append(alert);
    showMessage(message, true);
  };

  const postResponse = async response => {
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) {
      throw new Error(response.redirected || /\/login|\/auth\//i.test(response.url)
        ? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại trước khi thực hiện.'
        : `Server trả về HTML thay vì JSON (HTTP ${response.status}). Kiểm tra file admin/products/list.php trên hosting.`);
    }
    const data = await response.json();
    if (!data || typeof data.ok !== 'boolean') throw new Error('Phản hồi máy chủ không hợp lệ.');
    if (!response.ok || !data.ok) throw new Error(data.message || `Lỗi HTTP ${response.status}.`);
    return data;
  };

  const readPage = async url => {
    const response = await fetch(url.href, {
      method: 'GET', credentials: 'same-origin', redirect: 'follow', cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
    });
    if (!response.ok || !isPageUrl(new URL(response.url))) {
      throw new Error('Không thể tải danh sách kho mới. Có thể phiên đăng nhập đã hết hạn.');
    }
    const html = await response.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    if (doc.querySelector('meta[name="redline-inventory-ajax"]')?.content !== build || !doc.querySelector('.inv-results-panel') || doc.querySelectorAll('#inventory-main .inv-finder').length !== 1) {
      throw new Error('Máy chủ chưa trả đúng trang Inventory mới. Kiểm tra phiên đăng nhập và bản PHP đã cập nhật.');
    }
    return doc;
  };

  const fieldsByName = form => {
    const out = [];
    for (const el of form.querySelectorAll('input,select,textarea')) {
      if (!el.name || ['hidden','submit'].includes(el.type)) continue;
      out.push({ name: el.name, value: el.value, checked: el.checked, type: el.type });
    }
    return out;
  };
  const restoreFields = (form, values) => {
    if (!form) return;
    for (const saved of values) {
      const el = Array.from(form.elements).find(item => item.name === saved.name);
      if (!el) continue;
      if (saved.type === 'checkbox' || saved.type === 'radio') el.checked = saved.checked;
      else el.value = saved.value;
    }
  };
  const getRowKey = row => row.querySelector('.inv-restock-form [name="part_id"]')?.value;
  const captureRows = () => {
    const data = new Map();
    for (const row of document.querySelectorAll('#inventory-table-body .inv-part-row')) {
      const key = getRowKey(row);
      if (!key) continue;
      const edit = row.querySelector('.inv-edit-part');
      data.set(key, { restock: row.querySelector('.inv-restock-form [name="add_quantity"]')?.value,
        editOpen: !!edit?.open,
        editFields: edit?.open ? fieldsByName(edit.querySelector('form')) : null });
    }
    return data;
  };
  const restoreRows = (data, savedPartId, action) => {
    for (const row of document.querySelectorAll('#inventory-table-body .inv-part-row')) {
      const key = getRowKey(row);
      const saved = data.get(key);
      if (!saved) continue;
      const qty = row.querySelector('.inv-restock-form [name="add_quantity"]');
      if (qty && saved.restock !== undefined) qty.value = saved.restock;
      const edit = row.querySelector('.inv-edit-part');
      if (edit && saved.editOpen) {
        edit.open = true;
        if (!(action === 'update_part' && key === savedPartId) && saved.editFields) restoreFields(edit.querySelector('form'), saved.editFields);
      }
    }
  };

  const swapSelector = (sourceDoc, selector) => {
    const oldElement = document.querySelector(selector);
    const newElement = sourceDoc.querySelector(selector);
    if (oldElement && newElement) oldElement.replaceWith(document.importNode(newElement, true));
  };
  const syncOptionalChips = sourceDoc => {
    const finder = document.querySelector('.inv-finder');
    const existing = finder?.querySelector('.inv-active-filters');
    const fresh = sourceDoc.querySelector('.inv-active-filters');
    if (existing && !fresh) existing.remove();
    else if (!existing && fresh) finder.append(document.importNode(fresh, true));
    else if (existing && fresh) existing.replaceWith(document.importNode(fresh, true));
  };
  const syncMovementPicker = sourceDoc => {
    const current = document.querySelector('.inv-operation-form select[name="part_id"]');
    const fresh = sourceDoc.querySelector('.inv-operation-form select[name="part_id"]');
    if (!current || !fresh) return;
    const selected = current.value;
    current.replaceChildren(...Array.from(fresh.options, option => document.importNode(option, true)));
    if (Array.from(current.options).some(option => option.value === selected)) current.value = selected;
  };
  const renderPage = (doc, { navigation = false, action = '', partId = '' } = {}) => {
    const s = captureScroll();
    const rows = navigation ? new Map() : captureRows();
    // Replace the OUTER filter section, never the form inside it.
    // Replacing #inventory-filters with .inv-finder nested a new filter section on each click.
    // Replacing the outermost section also repairs any duplicates left by the old script.
    const finder = main.querySelector('.inv-finder');
    const movementSelector = document.querySelector('.inv-audit-filter select[name="movement_type"]');
    const currentSearch = document.getElementById('topbar-search');
    const keepSearchFocus = document.activeElement === currentSearch;
    for (const selector of ['.inv-overview', '.inv-operations-summary', '.inv-stock-tabs', '.inv-results-panel', '.inv-audit-scroll']) swapSelector(doc, selector);
    const nextSidebarCount = doc.getElementById('sidebar-cnt');
    if (nextSidebarCount && document.getElementById('sidebar-cnt')) document.getElementById('sidebar-cnt').textContent = nextSidebarCount.textContent;
    if (navigation) {
      const freshFinder = doc.getElementById('inventory-main')?.querySelector('.inv-finder');
      if (!finder || !freshFinder || !freshFinder.querySelector('#inventory-filters')) {
        throw new Error('Phản hồi không có bộ lọc Inventory hợp lệ.');
      }
      finder.replaceWith(document.importNode(freshFinder, true));
      swapSelector(doc, '.inv-inline-search');
      const nextQ = doc.getElementById('topbar-search');
      if (nextQ) {
        document.getElementById('topbar-search').value = nextQ.value;
        const topForm = document.getElementById('inventory-topbar-search');
        for (const name of ['category', 'brand', 'stock']) {
          const current = topForm.elements.namedItem(name);
          const fresh = doc.getElementById('inventory-topbar-search')?.elements.namedItem(name);
          if (current && fresh) current.value = fresh.value;
        }
      }
      if (keepSearchFocus) document.getElementById('topbar-search').focus({preventScroll:true});
    } else {
      syncOptionalChips(doc);
    }
    if (movementSelector) {
      const next = doc.querySelector('.inv-audit-filter select[name="movement_type"]');
      if (next) movementSelector.value = next.value;
    }
    if (!navigation) restoreRows(rows, partId, action);
    syncMovementPicker(doc);
    restoreScroll(s);
  };

  const navigate = async (url, { push = true } = {}) => {
    if (busyPost) { showMessage('Đang lưu dữ liệu kho. Vui lòng hoàn tất thao tác hiện tại.', true); return; }
    const id = ++navSequence;
    hideMessage();
    main.setAttribute('aria-busy', 'true');
    try {
      const doc = await readPage(url);
      if (id !== navSequence) return;
      renderPage(doc, { navigation: true });
      if (push) history.pushState({ inventoryAjax: true }, '', url.href);
    } catch (err) { if (id === navSequence) showMessage(err.message || 'Không tải được dữ liệu kho.', true); }
    finally { if (id === navSequence) main.removeAttribute('aria-busy'); }
  };

  const submitPost = async form => {
    if (busyPost) return;
    if (promptInvalid(form)) return;
    clearInlineError(form);
    const data = new FormData(form);
    const action = String(data.get('action') || '');
    if (!validPostActions.has(action)) return;
    const partId = String(data.get('part_id') || '');
    const submitter = form.querySelector('button[type="submit"]');
    const scrollBefore = captureScroll();
    const postUrl = formAction(form);
    if (!isPageUrl(postUrl)) { inlineError(form, 'Đường dẫn xử lý kho không hợp lệ.'); return; }
    busyPost = true;
    ++navSequence; // invalidate in-flight GET so it cannot overwrite a saved result
    if (submitter) submitter.disabled = true;
    showMessage('Đang lưu dữ liệu kho…');
    try {
      const response = await fetch(postUrl.href, {
        method: 'POST', credentials: 'same-origin', redirect: 'follow', cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: data
      });
      const result = await postResponse(response);
      // The POST is already committed. A failed GET must never trigger a second POST.
      let refreshed = false;
      try {
        const doc = await readPage(currentUrl());
        renderPage(doc, { navigation: false, action, partId });
        refreshed = true;
      } catch (refreshError) {
        showMessage((result.message || 'Đã lưu thành công.') + ' Tuy nhiên chưa tải được dữ liệu mới; kiểm tra lại bằng F5, không bấm lưu lần hai.', true);
      }
      if (refreshed) {
        showMessage(result.message || 'Đã lưu thành công.');
        if (action === 'add_part') {
          form.reset();
          document.getElementById('close-part-modal')?.click();
        }
        if (action === 'stock_adjust') {
          const reason = form.elements.namedItem('reason');
          if (reason) reason.value = '';
        }
      }
    } catch (err) {
      const message = err instanceof TypeError
        ? 'Mất kết nối khi gửi yêu cầu. Chưa xác định đã lưu hay chưa; vui lòng kiểm tra số tồn và lịch sử giao dịch trước khi thử lại.'
        : (err.message || 'Không lưu được dữ liệu kho.');
      if (form.isConnected) inlineError(form, message);
      else showMessage(message, true);
    } finally {
      if (submitter && submitter.isConnected) submitter.disabled = false;
      busyPost = false;
      // Preserve original scroll even when an alert is inserted or a modal is dismissed.
      restoreScroll(scrollBefore);
    }
  };

  // Delegate handlers because row forms and table controls are replaced after every update.
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !main.contains(form) && !document.getElementById('part-create-modal')?.contains(form)) return;
    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method === 'POST') {
      const action = form.querySelector('input[name="action"]')?.value;
      if (!validPostActions.has(action)) return;
      event.preventDefault();
      submitPost(form);
      return;
    }
    if (method === 'GET' && isPageUrl(formAction(form))) {
      event.preventDefault();
      const url = formAction(form);
      // Movement history selection must NOT clear the current parts filters.
      if (form.classList.contains('inv-audit-filter')) {
        const next = currentUrl();
        const type = form.querySelector('select[name="movement_type"]')?.value || '';
        if (type) next.searchParams.set('movement_type', type); else next.searchParams.delete('movement_type');
        next.hash = 'movement-history';
        navigate(next);
      } else {
        const next = new URL(url.href);
        const data = new FormData(form);
        if (form.id === 'inventory-topbar-search') {
          const filters = document.getElementById('inventory-filters');
          for (const key of ['category', 'brand', 'stock']) data.set(key, filters?.elements.namedItem(key)?.value || '');
        }
        for (const [key,value] of data.entries()) next.searchParams.set(key, String(value));
        next.searchParams.delete('page');
        const priorMovement = currentUrl().searchParams.get('movement_type');
        if (priorMovement) next.searchParams.set('movement_type', priorMovement);
        navigate(next);
      }
    }
  }, true);

  // Browser HTML5 validation blocks submit events. Capture invalid fields to provide clear positioning.
  document.addEventListener('invalid', event => {
    const form = event.target.closest('form');
    if (!form || !main.contains(form) && !document.getElementById('part-create-modal')?.contains(form)) return;
    const details = event.target.closest('details');
    if (details) details.open = true;
    requestAnimationFrame(() => event.target.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    showMessage('Vui lòng nhập hoặc sửa trường được đánh dấu.', true);
  }, true);

  main.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const anchor = event.target.closest('a[href]');
    if (!anchor || anchor.target && anchor.target !== '_self' || anchor.hasAttribute('download')) return;
    const url = new URL(anchor.getAttribute('href'), location.href);
    if (!isPageUrl(url)) return;
    event.preventDefault();
    navigate(url);
  });

  main.addEventListener('change', event => {
    const control = event.target;
    if (!control.matches('.inv-audit-filter select[name="movement_type"]')) return;
    // Unlike the old inline onchange=form.submit(), prevent navigation entirely.
    const url = currentUrl();
    if (control.value) url.searchParams.set('movement_type', control.value);
    else url.searchParams.delete('movement_type');
    url.hash = 'movement-history';
    navigate(url);
  });

  window.addEventListener('popstate', () => {
    if (isPageUrl(currentUrl())) navigate(currentUrl(), { push: false });
  });
})();
