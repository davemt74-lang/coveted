(() => {
  'use strict';

  const form = document.querySelector('[data-benefit-program-builder]');
  if (!form) return;

  const ownerType = form.querySelector('[data-program-owner-type]');
  const businessRef = form.querySelector('[data-program-business-ref]');
  const claimMode = form.querySelector('[data-program-claim-mode]');
  const locationRef = form.querySelector('[data-program-location-ref]');
  const ownerFields = Array.from(form.querySelectorAll('[data-program-owner]'));

  const refreshOwnerFields = () => {
    const selected = ownerType ? ownerType.value : 'group';
    ownerFields.forEach((field) => {
      const active = field.getAttribute('data-program-owner') === selected;
      field.hidden = !active;
      const select = field.querySelector('select');
      if (select) select.disabled = !active;
    });

    if (claimMode) {
      const locationOption = claimMode.querySelector('option[value="location_code"]');
      if (locationOption) locationOption.disabled = selected !== 'business';
      if (selected !== 'business' && claimMode.value === 'location_code') {
        claimMode.value = 'none';
      }
    }
    refreshLocations();
  };

  const refreshLocations = () => {
    if (!locationRef) return;
    const selectedOwner = ownerType ? ownerType.value : '';
    const selectedBusiness = businessRef ? businessRef.value : '';
    let selectedStillValid = locationRef.value === '';

    Array.from(locationRef.options).forEach((option, index) => {
      if (index === 0) return;
      const matches = selectedOwner === 'business'
        && selectedBusiness !== ''
        && option.getAttribute('data-business-ref') === selectedBusiness;
      option.hidden = !matches;
      option.disabled = !matches;
      if (matches && option.value === locationRef.value) selectedStillValid = true;
    });

    locationRef.disabled = selectedOwner !== 'business';
    if (!selectedStillValid) locationRef.value = '';
  };

  ownerType?.addEventListener('change', refreshOwnerFields);
  businessRef?.addEventListener('change', refreshLocations);
  refreshOwnerFields();
})();
