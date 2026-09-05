(() => {
    'use strict';

    const links = [
        ['/privacy.php', 'Privacy'],
        ['/terms.php', 'Terms'],
    ];

    const landingNav = document.querySelector('.cv-landing-footer nav');
    if (landingNav) {
        links.forEach(([href, label]) => {
            if (landingNav.querySelector(`a[href="${href}"]`)) return;
            const link = document.createElement('a');
            link.href = href;
            link.textContent = label;
            link.className = 'cv-legal-link';
            landingNav.appendChild(link);
        });
        return;
    }

    if (document.body.classList.contains('cv-admin-body') || document.querySelector('.cv-legal-footer')) return;

    const main = document.querySelector('main.cv-main');
    if (!main) return;

    const footer = document.createElement('footer');
    footer.className = 'cv-legal-footer';
    footer.innerHTML = '<span>© Coveted</span><nav aria-label="Legal"></nav>';
    const nav = footer.querySelector('nav');
    links.forEach(([href, label]) => {
        const link = document.createElement('a');
        link.href = href;
        link.textContent = label;
        nav.appendChild(link);
    });
    main.insertAdjacentElement('afterend', footer);
})();
