/* Customer appointment history: search saved requests and switch accessible tabs. */
(function () {
  'use strict';
  var history = document.querySelector('[data-appointments]');
  if (!history) return;
  var tabs = Array.from(history.querySelectorAll('[data-appointment-tab]'));
  var panels = Array.from(history.querySelectorAll('[data-appointment-panel]'));
  var inputs = [document.getElementById('customer-appointment-search'), document.getElementById('customer-appointment-topbar-search')].filter(Boolean);
  var result = history.querySelector('[data-appointment-results]');
  var clear = history.querySelector('[data-appointment-clear]');
  var selected = tabs.find(function (tab) { return tab.getAttribute('aria-selected') === 'true'; }).dataset.appointmentTab;
  var query = '';
  function normalize(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase(); }
  function render() {
    var visible = 0, total = 0;
    panels.forEach(function (panel) {
      var active = panel.dataset.appointmentPanel === selected;
      panel.hidden = !active;
      var cards = Array.from(panel.querySelectorAll('[data-appointment-card]'));
      var matches = 0;
      cards.forEach(function (card) {
        card.hidden = !normalize(card.dataset.appointmentSearch).includes(query);
        if (!card.hidden) matches++;
      });
      var empty = panel.querySelector('[data-appointment-no-results]');
      if (empty) empty.hidden = matches !== 0;
      if (active) { visible = matches; total = cards.length; }
    });
    tabs.forEach(function (tab) {
      var active = tab.dataset.appointmentTab === selected;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    clear.hidden = query === '';
    result.textContent = 'Showing ' + visible + (query ? ' of ' + total : '') + ' ' + selected + ' request' + (visible === 1 ? '' : 's');
  }
  function selectTab(tab) {
    selected = tab.dataset.appointmentTab;
    var url = new URL(window.location.href);
    url.searchParams.set('tab', selected);
    window.history.replaceState(null, '', url);
    render();
  }
  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); selectTab(tab);
    });
    tab.addEventListener('keydown', function (event) {
      var next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      else if (event.key === ' ') next = index;
      else return;
      event.preventDefault(); tabs[next].focus(); selectTab(tabs[next]);
    });
  });
  inputs.forEach(function (input) {
    input.addEventListener('input', function () {
      query = normalize(input.value.trim());
      inputs.forEach(function (other) { if (other !== input) other.value = input.value; });
      render();
    });
  });
  clear.addEventListener('click', function () {
    query = ''; inputs.forEach(function (input) { input.value = ''; });
    render(); inputs[0].focus();
  });
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !document.querySelector('.modal-backdrop:not([hidden])')) {
      event.preventDefault();
      var input = inputs.find(function (field) { return field.getClientRects().length; });
      if (input) { input.focus(); input.select(); }
    }
  });
  render();
})();
