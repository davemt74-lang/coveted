(() => {
    'use strict';

    const genderRadios = Array.from(document.querySelectorAll('input[name="gender"]'));
    const selfWrap = document.querySelector('[data-gender-self]');
    const selfInput = document.querySelector('[data-gender-self-input]');

    const syncGenderSelf = () => {
        if (!selfWrap || !selfInput) return;
        const selected = genderRadios.find((radio) => radio.checked)?.value || '';
        const show = selected === 'self_describe';
        selfWrap.hidden = !show;
        selfInput.required = show;
        if (!show) selfInput.value = '';
    };

    genderRadios.forEach((radio) => radio.addEventListener('change', syncGenderSelf));
    syncGenderSelf();
})();
