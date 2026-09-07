(() => {
    'use strict';
    const path = location.pathname;
    const shell = document.querySelector('.cv-admin-app');
    if (!shell || shell.dataset.systemSample === '1') return;

    const groupLink = Array.from(document.querySelectorAll('.cv-admin-primary-nav a')).find((link) => (link.getAttribute('href') || '') === '/admin/?view=groups');
    if (groupLink && !document.querySelector('[data-member-relationships-nav]')) {
        const link=document.createElement('a');
        link.dataset.memberRelationshipsNav='1';
        link.href='/admin/member-relationships.php';
        link.innerHTML='<span class="cv-admin-nav-text">Relationship Intelligence</span>';
        if(path==='/admin/member-relationships.php') link.classList.add('is-active');
        groupLink.insertAdjacentElement('afterend',link);
    }
})();
