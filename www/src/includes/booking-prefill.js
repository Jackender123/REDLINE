/**
 * Booking forms: fill the vehicle fields from the "Use a saved vehicle" dropdown.
 * File Location: www/public/assets/booking-prefill.js
 * Used by: services.php, performance.php, Fabrication.php
 */
(function () {
  'use strict';

  var select = document.getElementById('f-saved-vehicle');
  if (!select) return; // guests, staff, or no picker on this page

  // saved-vehicle key -> booking form field id
  var FIELDS = {
    make:    'f-make',
    model:   'f-model',
    plate:   'f-license',
    year:    'f-year',
    mileage: 'f-km',
    color:   'f-color'
  };

  function readVehicle(option) {
    try {
      return JSON.parse(option.getAttribute('data-vehicle') || 'null');
    } catch (err) {
      return null;
    }
  }

  function setField(id, value) {
    var el = document.getElementById(id);
    if (!el) return;
    el.value = value == null ? '' : value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }

  select.addEventListener('change', function () {
    var vehicle = readVehicle(select.options[select.selectedIndex]);

    // vehicle === null means "Enter a different vehicle" -> clear the fields
    Object.keys(FIELDS).forEach(function (key) {
      setField(FIELDS[key], vehicle ? vehicle[key] : '');
    });

    // The garage does not store a VIN, so it is typed again for each vehicle
    setField('f-vin', '');
  });

  // After a failed submit the form comes back with the posted values:
  // re-select the matching saved vehicle (by plate) without overwriting anything.
  var plate = document.getElementById('f-license');
  if (plate && plate.value) {
    var wanted = plate.value.trim().toUpperCase();
    Array.prototype.some.call(select.options, function (opt) {
      var v = readVehicle(opt);
      if (v && String(v.plate).toUpperCase() === wanted) {
        select.value = opt.value;
        return true;
      }
      return false;
    });
    return;
  }

  // Fresh form (nothing typed / posted yet): auto-select the most recent saved vehicle
  // (options are ordered by id DESC, so option[1] is the newest) and fill the fields.
  var anyTyped = ['f-make', 'f-model', 'f-year', 'f-km', 'f-color', 'f-vin'].some(function (id) {
    var el = document.getElementById(id);
    return el && el.value;
  });
  if (!anyTyped && select.options.length > 1) {
    select.selectedIndex = 1;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }
})();