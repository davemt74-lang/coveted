(() => {
    'use strict';
    const shell=document.querySelector('.cv-admin-app');
    if(!shell || shell.dataset.systemSample==='1') return;
    const params=new URLSearchParams(location.search);
    const eventRef=(params.get('event')||'').trim();

    if(eventRef){
        const tabs=document.querySelector('.cv-admin-event-tabs');
        if(tabs && !tabs.querySelector('[data-event-guest-mix-tab]')){
            const link=document.createElement('a');
            link.dataset.eventGuestMixTab='1';
            link.href=`/admin/event-guest-mix.php?event=${encodeURIComponent(eventRef)}`;
            link.textContent='Guest Mix';
            if(location.pathname==='/admin/event-guest-mix.php')link.classList.add('is-active');
            tabs.appendChild(link);
        }
        const actions=document.querySelector('.cv-admin-event-top-actions,.cv-admin-page-head .cv-action-row');
        if(actions && !actions.querySelector('[data-event-guest-mix-action]')){
            const link=document.createElement('a');
            link.dataset.eventGuestMixAction='1';
            link.className='cv-button cv-button-soft';
            link.href=`/admin/event-guest-mix.php?event=${encodeURIComponent(eventRef)}`;
            link.textContent='Guest Mix';
            actions.prepend(link);
        }
    }
})();
