(() => {
    'use strict';

    if (location.pathname !== '/host.php') return;

    const params = new URLSearchParams(location.search);
    const eventRef = (params.get('event') || '').trim();
    if (!eventRef) return;

    const tabs = document.querySelector('.cv-tab-row[aria-label="Event host sections"]');
    if (!tabs || tabs.querySelector('[data-host-command-link]')) return;

    const link = document.createElement('a');
    link.className = 'cv-tab';
    link.dataset.hostCommandLink = '1';
    link.href = `/host-command.php?event=${encodeURIComponent(eventRef)}`;
    link.textContent = 'Command Center';

    const memberView = Array.from(tabs.querySelectorAll('a')).find((node) => (node.getAttribute('href') || '') === '/events.php');
    if (memberView) memberView.insertAdjacentElement('beforebegin', link);
    else tabs.appendChild(link);
})();
