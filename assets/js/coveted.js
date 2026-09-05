(() => {
    'use strict';

    const loadScript = (src) => new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = false;
        script.onload = resolve;
        script.onerror = () => reject(new Error(`Unable to load ${src}`));
        document.head.appendChild(script);
    });

    (async () => {
        await loadScript('/assets/js/coveted-base.js?v=member-v2-20260905');
        await loadScript('/assets/js/legal-footer.js?v=legal-footer-20260905');
        await loadScript('/assets/js/member-v2.js?v=member-v2-20260905');
        await loadScript('/assets/js/admin-v2.js?v=admin-v2-people-business-20260905');
        await loadScript('/assets/js/admin-platform-v2.js?v=admin-platform-v2-20260905');
        await loadScript('/assets/js/admin-community-cleanup-v2.js?v=admin-community-cleanup-v2-20260905');
        await loadScript('/assets/js/landing-event-images.js?v=landing-event-images-20260905');
        await loadScript('/assets/js/landing-network-v2.js?v=landing-network-v2-20260905');
        await loadScript('/assets/js/invite-crm-v2.js?v=invite-crm-v2-20260905');
        await loadScript('/assets/js/invite-profile-v2.js?v=invite-profile-v2-20260905');
    })().catch((error) => console.error('Coveted application scripts failed to load:', error));
})();
