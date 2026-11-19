/* REDLINE unified workshop queue: service bookings and all repair orders, including walk-ins. */
(() => {
    'use strict';
    const root = document.getElementById('appointments-workspace');
    if (!root) return;

    const $ = id => document.getElementById(id);
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[ch]);
    const statusLabels = {
        pending: 'Unlinked Request', awaiting_approval: 'Awaiting Approval',
        received: 'Received', diagnosing: 'Diagnosing', repairing: 'Repairing',
        waiting_parts: 'Waiting Parts', inspecting: 'Inspecting',
        completed: 'Completed / Ready', delivered: 'Delivered', cancelled: 'Cancelled'
    };
    const statusClasses = {
        pending: 'bg-amber-400/20 text-amber-200 border-amber-300/40',
        awaiting_approval: 'bg-amber-400/20 text-amber-200 border-amber-300/40',
        received: 'bg-white/10 text-slate-100 border-white/30',
        diagnosing: 'bg-sky-400/20 text-sky-200 border-sky-300/40',
        repairing: 'bg-amber-400/20 text-amber-200 border-amber-300/40',
        waiting_parts: 'bg-orange-400/20 text-orange-200 border-orange-300/40',
        inspecting: 'bg-purple-400/20 text-purple-200 border-purple-300/40',
        completed: 'bg-emerald-400/20 text-emerald-200 border-emerald-300/40',
        delivered: 'bg-blue-400/20 text-blue-200 border-blue-300/40',
        cancelled: 'bg-rose-400/20 text-rose-200 border-rose-300/40'
    };
    const workshopStatuses = new Set(['received', 'diagnosing', 'repairing', 'waiting_parts', 'inspecting']);

    let records = Array.isArray(window.REDLINE_WORKSHOP_RECORDS) ? window.REDLINE_WORKSHOP_RECORDS : [];
    let snapshot = JSON.stringify(records);
    let renderedRows = null;
    let refreshing = false;
    let activeTab = 'ALL';
    let currentPage = 1;
    let selectedKey = '';
    let lastTrigger = null;

    const frame = $('order-inspector-frame');
    const inspector = $('order-inspector');
    const inspectorLoading = $('order-inspector-loading');
    const pageSizeSelector = $('appointments-page-size');

    window.toggleMobileSidebar = function () {
        $('main-sidebar').classList.toggle('-translate-x-full');
        $('mobile-sidebar-backdrop').classList.toggle('hidden');
    };

    const count = query => records.filter(query).length;
    const put = (id, value) => { if ($(id)) $(id).textContent = String(value); };
    const statusText = status => statusLabels[status] || status || 'Unknown';
    const badge = status => `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border ${statusClasses[status] || 'bg-white/10 text-slate-100 border-white/30'}">${escapeHtml(statusText(status))}</span>`;
    const detailUrl = item => item.kind === 'order'
        ? `../orders/detail.php?id=${encodeURIComponent(item.repair_order_id)}`
        : `list.php?selected=${encodeURIComponent(item.key)}`;
    const canOpen = item => item.kind === 'order' || item.status !== 'cancelled';

    function updateCounters() {
        const pending = count(r => r.status === 'pending');
        const awaiting = count(r => r.status === 'awaiting_approval');
        const workshop = count(r => workshopStatuses.has(r.status));
        put('kpi-total', records.length);
        put('kpi-pending', pending);
        put('kpi-approval', awaiting);
        put('kpi-accepted', workshop);
        put('cnt-tab-ALL', records.length);
        put('cnt-tab-approval', awaiting);
        put('cnt-tab-workshop', workshop);
        put('sidebar-cnt', pending + awaiting || records.length);
        put('workshop-active-orders', count(r => r.kind === 'order' && (workshopStatuses.has(r.status) || r.status === 'awaiting_approval')));
        put('workshop-repairing', count(r => r.status === 'diagnosing' || r.status === 'repairing'));
        put('workshop-parts', count(r => r.status === 'waiting_parts'));
        put('workshop-inspecting', count(r => r.status === 'inspecting'));
        put('workshop-completed', count(r => r.status === 'completed'));
        put('workshop-delivered', count(r => r.status === 'delivered'));
    }

    function updateTechnicianFilter() {
        const select = $('select-tech');
        const previous = select.value;
        const technicians = [...new Set(records.map(item => item.technician_name).filter(Boolean))].sort((a, b) => a.localeCompare(b));
        select.replaceChildren(new Option('All technicians', 'ALL'), new Option('Unassigned', 'unassigned'));
        technicians.forEach(name => select.add(new Option(name, name)));
        select.value = [...select.options].some(opt => opt.value === previous) ? previous : 'ALL';
    }

    function matches(item) {
        if (activeTab === 'workshop' && !workshopStatuses.has(item.status)) return false;
        if (activeTab !== 'ALL' && activeTab !== 'workshop' && activeTab !== item.status) return false;
        if ($('select-status').value !== 'ALL' && $('select-status').value !== item.status) return false;
        const tech = $('select-tech').value;
        if (tech === 'unassigned' && item.technician_name) return false;
        if (tech !== 'unassigned' && tech !== 'ALL' && item.technician_name !== tech) return false;
        const search = $('table-search').value.trim().toLocaleLowerCase();
        return !search || [item.order_code, item.full_name, item.phone, item.license_plate,
            item.vehicle_model, item.vin_number, item.service_type, item.message,
            item.technician_name, item.preferred_technician_name, item.service_request_id]
            .some(value => String(value ?? '').toLocaleLowerCase().includes(search));
    }

    function setTab(tab) {
        activeTab = ['ALL', 'awaiting_approval', 'workshop'].includes(tab) ? tab : 'ALL';
        $('select-status').value = 'ALL';
        currentPage = 1;
        ['ALL', 'awaiting_approval', 'workshop'].forEach(value => {
            const button = $('tab-' + value);
            button.setAttribute('aria-pressed', String(value === activeTab));
        });
        renderTable();
    }
    window.setTab = setTab;

    function renderTable() {
        const filtered = records.filter(matches);
        const size = Number(pageSizeSelector.value) || 10;
        const pageCount = Math.max(1, Math.ceil(filtered.length / size));
        currentPage = Math.min(Math.max(1, currentPage), pageCount);
        const start = (currentPage - 1) * size;
        const pageItems = filtered.slice(start, start + size);
        put('appointments-results', filtered.length
            ? `Showing ${start + 1}–${Math.min(start + size, filtered.length)} of ${filtered.length} records`
            : 'No matching appointments or repair orders');
        put('appointments-page-label', `${currentPage} / ${pageCount}`);
        $('appointments-prev').disabled = currentPage === 1;
        $('appointments-next').disabled = currentPage === pageCount;

        const renderKey = JSON.stringify([pageItems, selectedKey]);
        if (renderKey === renderedRows) return;
        renderedRows = renderKey;

        if (pageItems.length === 0) {
            $('appointments-tbody').innerHTML = '<tr><td colspan="6" class="p-8 text-center text-slate-300 text-xs">No records match the current filters.</td></tr>';
            $('appointments-compact-list').innerHTML = '<p class="appointments-compact-empty">No matching records.</p>';
            return;
        }

        $('appointments-tbody').innerHTML = pageItems.map(item => {
            const url = detailUrl(item);
            const actionLabel = 'View / manage';
            const action = canOpen(item)
                ? `<a href="${url}" data-detail-key="${escapeHtml(item.key)}" class="unified-action">${actionLabel} →</a>`
                : '<span class="text-xs text-slate-300">Cancelled</span>';
            const preferred = !item.technician_name && item.preferred_technician_name
                ? `<div class="text-xs text-emerald-200 mt-1">Preferred: ${escapeHtml(item.preferred_technician_name)}</div>` : '';
            const typeLabel = item.kind === 'appointment' ? 'Online request' : (item.source === 'walkin' ? 'Workshop order' : 'Booking → order');
            return `<tr class="unified-record-row ${item.key === selectedKey ? 'is-selected' : ''}">
                <td class="py-3.5 px-4">
                    <div class="font-semibold text-red-300 font-mono">${escapeHtml(item.order_code || '—')}</div>
                    <div class="text-[11px] text-slate-300 mt-1">${escapeHtml(item.created_at)}</div>
                    <div class="text-[11px] text-slate-200 mt-1">${escapeHtml(item.service_type || '')}</div>
                    <div class="text-[10px] text-slate-400 uppercase tracking-wide">${escapeHtml(typeLabel)}</div>
                </td>
                <td class="py-3.5 px-4"><div class="font-semibold">${escapeHtml(item.vehicle_model)}</div>
                    <span class="unified-plate">${escapeHtml(item.license_plate)}</span></td>
                <td class="py-3.5 px-4"><div class="font-semibold">${escapeHtml(item.full_name)}</div>
                    <div class="text-xs text-slate-300 mt-1">${escapeHtml(item.phone)}</div></td>
                <td class="py-3.5 px-4"><div class="font-semibold">${escapeHtml(item.technician_name || 'Unassigned')}</div>${preferred}</td>
                <td class="py-3.5 px-4">${badge(item.status)}</td>
                <td class="py-3.5 px-4 text-right">${action}</td>
            </tr>`;
        }).join('');

        $('appointments-compact-list').innerHTML = pageItems.map(item => {
            const url = detailUrl(item);
            const action = canOpen(item)
                ? `<a data-detail-key="${escapeHtml(item.key)}" href="${url}" class="appointment-compact-action">Open →</a>`
                : '<span class="text-xs text-slate-300">Cancelled</span>';
            return `<article class="appointment-compact-card${item.key === selectedKey ? ' is-selected' : ''}">
                <div class="appointment-compact-top"><strong>${escapeHtml(item.order_code || '—')}</strong>
                    <span class="appointment-compact-status">${escapeHtml(statusText(item.status))}</span></div>
                <div class="appointment-compact-meta"><span>${escapeHtml(item.full_name)}</span>
                    <span class="appointment-compact-plate">${escapeHtml(item.license_plate)}</span></div>
                <p class="appointment-compact-service">${escapeHtml(item.vehicle_model)} · ${escapeHtml(item.service_type || '')}</p>
                <p class="appointment-compact-service">Technician: ${escapeHtml(item.technician_name || 'Unassigned')}</p>
                <div class="appointment-compact-footer"><span>${escapeHtml(item.created_at)}</span>${action}</div>
            </article>`;
        }).join('');
    }

    function normalizedKey(key) {
        const value = String(key || '');
        if (/^[ao]-[1-9]\d*$/.test(value)) return value;
        if (/^[1-9]\d*$/.test(value)) {
            const request = records.find(item => Number(item.service_request_id) === Number(value));
            return request ? request.key : '';
        }
        return '';
    }
    function setInspector(key, saveHistory = true) {
        key = normalizedKey(key);
        const item = records.find(row => row.key === key);
        if (!item || item.kind !== 'order' || !canOpen(item)) return;
        const changed = selectedKey !== key;
        selectedKey = key;
        $('order-inspector-title').textContent = 'Repair Order Detail';
        $('order-inspector-subtitle').textContent = `${item.full_name} · ${item.license_plate} · ${item.order_code}`;
        inspector.hidden = false;
        root.classList.add('inspector-open');
        if (changed || !frame.getAttribute('src')) {
            inspectorLoading.hidden = false;
            frame.src = detailUrl(item) + (detailUrl(item).includes('?') ? '&' : '?') + 'embed=1';
        }
        if (saveHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('selected', key);
            window.history.pushState({ key }, '', url);
        }
        renderedRows = null;
        renderTable();
    }
    function closeInspector(saveHistory = true) {
        if (!selectedKey) return;
        selectedKey = '';
        inspector.hidden = true;
        root.classList.remove('inspector-open');
        frame.removeAttribute('src');
        if (saveHistory) {
            const url = new URL(window.location.href);
            url.searchParams.delete('selected');
            window.history.pushState({ key: '' }, '', url);
        }
        renderedRows = null;
        renderTable();
        if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
    }

    // Legacy pending appointments are converted by an explicit, CSRF-protected
    // POST, never by rendering the removed Receive Vehicle form or a GET mutation.
    let resolvingRequest = false;
    async function openRecord(key, saveHistory = true) {
        key = normalizedKey(key);
        const item = records.find(r => r.key === key);
        if (!item || !canOpen(item)) return;
        if (item.kind === 'order') { setInspector(key, saveHistory); return; }
        if (resolvingRequest) return;
        resolvingRequest = true;
        const problem = $('appointments-error');
        problem.hidden = false;
        problem.textContent = 'Linking this appointment to the workshop order…';
        try {
            const data = new URLSearchParams({
                service_request_id: String(item.service_request_id),
                csrf: window.REDLINE_WORKSHOP_CSRF || ''
            });
            const response = await fetch('../../api/orders/from_request.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: data.toString()
            });
            const result = await response.json();
            if (!response.ok || !result.ok || !Number.isSafeInteger(Number(result.order_id)) || Number(result.order_id) < 1) {
                throw new Error(result.message || 'The request could not be linked.');
            }
            const orderId = Number(result.order_id);
            // Update the local row immediately, even if the periodic refresh has
            // another request in flight. The next refresh supplies canonical data.
            const orderKey = `o-${orderId}`;
            records = records.map(r => r.key === item.key ? {
                ...r, key: orderKey, kind: 'order', repair_order_id: orderId,
                status: 'awaiting_approval', order_code: String(result.order_code || r.order_code)
            } : r);
            snapshot = JSON.stringify(records);
            updateCounters(); updateTechnicianFilter(); renderedRows = null; renderTable();
            problem.hidden = true; problem.textContent = '';
            setInspector(orderKey, saveHistory);
            if (!saveHistory) {
                const url = new URL(window.location.href);
                url.searchParams.set('selected', orderKey);
                window.history.replaceState({ key: orderKey }, '', url);
            }
            void refreshRecords(true);
        } catch (error) {
            problem.textContent = error.message || 'Unable to open this request.';
            problem.hidden = false;
        } finally {
            resolvingRequest = false;
        }
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-detail-key]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        lastTrigger = link;
        void openRecord(link.dataset.detailKey);
    });
    $('order-inspector-close').addEventListener('click', () => closeInspector());
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && selectedKey) closeInspector();
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            $('topbar-search').focus();
            $('topbar-search').select();
        }
    });
    window.addEventListener('popstate', () => {
        const selected = new URL(window.location.href).searchParams.get('selected');
        if (selected) void openRecord(selected, false);
        else closeInspector(false);
    });
    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
        if (event.data?.type === 'redline:close-order-inspector') closeInspector();
    });
    frame.addEventListener('load', () => {
        try {
            const location = frame.contentWindow.location;
            if (location.origin !== window.location.origin) return;
            if (/\/auth\/login\.php$/.test(location.pathname)) {
                window.location.assign(location.href);
                return;
            }
            if (/\/admin\/orders\/(detail|create)\.php$/.test(location.pathname)) {
                const url = new URL(location.href);
                if (url.searchParams.get('embed') !== '1') {
                    url.searchParams.set('embed', '1');
                    frame.contentWindow.location.replace(url.href);
                    return;
                }
            }
        } catch (err) {
            // Cross-origin navigation is never inspected or altered.
        }
        inspectorLoading.hidden = true;
        refreshRecords(true);
    });

    async function refreshRecords(force = false) {
        if ((!force && document.hidden) || refreshing) return;
        refreshing = true;
        const abortController = new AbortController();
        const timeout = window.setTimeout(() => abortController.abort(), 10000);
        const problem = $('appointments-error');
        const status = $('booking-stream-status');
        const dot = $('booking-stream-dot');
        let errorMessage = 'Could not update the workshop list. Showing the last loaded records.';
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('refresh', '1');
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: abortController.signal });
            const result = await response.json();
            if (!response.ok || !result.ok || !Array.isArray(result.appointments)) {
                errorMessage = result.error || errorMessage;
                throw new Error(errorMessage);
            }
            const nextSnapshot = JSON.stringify(result.appointments);
            if (nextSnapshot !== snapshot) {
                records = result.appointments;
                snapshot = nextSnapshot;
                // Direct conversion replaces a pending request key a-# with order key o-#.
                if (selectedKey.startsWith('a-') && !records.some(item => item.key === selectedKey)) {
                    const requestId = Number(selectedKey.substring(2));
                    const linked = records.find(item => item.kind === 'order' && Number(item.service_request_id) === requestId);
                    if (linked) {
                        selectedKey = linked.key;
                        const selectedUrl = new URL(window.location.href);
                        selectedUrl.searchParams.set('selected', linked.key);
                        window.history.replaceState({ key: linked.key }, '', selectedUrl);
                    }
                }
                updateCounters();
                updateTechnicianFilter();
                renderedRows = null;
                renderTable();
            }
            problem.hidden = true;
            problem.textContent = '';
            status.textContent = 'LIVE WORKSHOP STREAM';
            dot.classList.remove('bg-amber-400');
            dot.classList.add('bg-emerald-400');
        } catch (error) {
            problem.hidden = false;
            problem.textContent = errorMessage;
            status.textContent = 'RECONNECTING';
            dot.classList.remove('bg-emerald-400');
            dot.classList.add('bg-amber-400');
        } finally {
            window.clearTimeout(timeout);
            refreshing = false;
        }
    }

    $('table-search').addEventListener('input', () => { currentPage = 1; renderTable(); });
    $('topbar-search').addEventListener('input', event => {
        $('table-search').value = event.target.value;
        currentPage = 1;
        renderTable();
    });
    $('select-status').addEventListener('change', () => {
        activeTab = 'ALL';
        ['ALL', 'awaiting_approval', 'workshop'].forEach(value => $('tab-' + value).setAttribute('aria-pressed', String(value === 'ALL')));
        currentPage = 1; renderTable();
    });
    $('select-tech').addEventListener('change', () => { currentPage = 1; renderTable(); });
    pageSizeSelector.addEventListener('change', () => { currentPage = 1; renderTable(); });
    $('appointments-prev').addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderTable(); } });
    $('appointments-next').addEventListener('click', () => { currentPage++; renderTable(); });

    updateCounters();
    updateTechnicianFilter();
    renderTable();
    const querySelection = new URL(window.location.href).searchParams.get('selected');
    if (querySelection) void openRecord(querySelection, false);
    window.setInterval(() => refreshRecords(), 15000);
    window.addEventListener('focus', () => refreshRecords());
    window.addEventListener('pageshow', () => refreshRecords());
    document.addEventListener('visibilitychange', () => refreshRecords());
})();
