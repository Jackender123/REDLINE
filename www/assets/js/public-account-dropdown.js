/* REDLINE public-site account dropdown: Services, Performance and Fabrication. */
(() => {
  'use strict';

  function initAccountDropdown() {
    const wrapper = document.getElementById('userDropdown');
    const trigger = document.getElementById('userMenuBtn');
    const menu = document.getElementById('userAccountMenu');
    if (!wrapper || !trigger || !menu) return;

    const isOpen = () => wrapper.classList.contains('open');
    function setOpen(open, restoreFocus = false) {
      wrapper.classList.toggle('open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (restoreFocus) trigger.focus();
    }

    trigger.addEventListener('click', event => {
      event.stopPropagation();
      setOpen(!isOpen());
    });

    document.addEventListener('pointerdown', event => {
      if (isOpen() && !wrapper.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', event => {
      if (!isOpen()) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        setOpen(false, true);
      }
    });

    // Close when tabbing out, without interfering with links inside the dropdown.
    wrapper.addEventListener('focusout', event => {
      if (!wrapper.contains(event.relatedTarget)) setOpen(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAccountDropdown, { once: true });
  } else {
    initAccountDropdown();
  }
})();
