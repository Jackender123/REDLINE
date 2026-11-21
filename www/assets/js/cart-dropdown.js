/** Shared customer activity dropdown. Content is supplied by the authenticated PHP page. */
(function () {
  'use strict';
  function initCartDropdowns() {
    var dropdowns = Array.prototype.slice.call(document.querySelectorAll('[data-cart-dropdown]'));
    if (!dropdowns.length) return;

    function setOpen(wrapper, open, restoreFocus) {
      var trigger = wrapper.querySelector('.cart-dropdown-trigger');
      var panel = wrapper.querySelector('.cart-dropdown-panel');
      if (!trigger || !panel) return;
      panel.hidden = !open;
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open && restoreFocus) trigger.focus();
    }
    function closeAll(except) {
      dropdowns.forEach(function (wrapper) {
        if (wrapper !== except) setOpen(wrapper, false, false);
      });
    }

    dropdowns.forEach(function (wrapper) {
      var trigger = wrapper.querySelector('.cart-dropdown-trigger');
      var panel = wrapper.querySelector('.cart-dropdown-panel');
      if (!trigger || !panel) return;
      trigger.addEventListener('click', function (event) {
        event.stopPropagation();
        var open = panel.hidden;
        closeAll();
        if (open) {
          var accountMenu = document.getElementById('userDropdown');
          var accountButton = document.getElementById('userMenuBtn');
          if (accountMenu) accountMenu.classList.remove('open');
          if (accountButton) accountButton.setAttribute('aria-expanded', 'false');
          var profileDropdown = document.getElementById('profile-dropdown');
          var profileMenu = document.getElementById('profile-menu');
          var profileTrigger = document.getElementById('profile-trigger');
          if (profileDropdown) profileDropdown.hidden = true;
          if (profileMenu) profileMenu.classList.remove('open');
          if (profileTrigger) profileTrigger.setAttribute('aria-expanded', 'false');
        }
        setOpen(wrapper, open, false);
      });
      panel.addEventListener('click', function (event) { event.stopPropagation(); });
      Array.prototype.forEach.call(panel.querySelectorAll('a, button'), function (action) {
        action.addEventListener('click', function () { setOpen(wrapper, false, false); });
      });
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('[data-cart-dropdown]')) closeAll();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        var opened = dropdowns.find(function (wrapper) {
          var panel = wrapper.querySelector('.cart-dropdown-panel');
          return panel && !panel.hidden;
        });
        if (opened) { setOpen(opened, false, true); event.stopPropagation(); }
      }
    });
    ['userMenuBtn', 'profile-trigger'].forEach(function (id) {
      var button = document.getElementById(id);
      if (button) button.addEventListener('click', function () { closeAll(); });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCartDropdowns);
  } else {
    initCartDropdowns();
  }
})();
