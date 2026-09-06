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
        await loadScript('/assets/js/attendee-event-nav-v1.js?v=attendee-event-nav-v1-20260906');
        await loadScript('/assets/js/daily-events-nav-v1.js?v=daily-events-nav-v1-20260906');
        await loadScript('/assets/js/partner-opportunities-v1.js?v=partner-opportunities-v1-20260906');
        await loadScript('/assets/js/partner-perks-v1.js?v=partner-perks-v1-20260906');
        await loadScript('/assets/js/admin-v2.js?v=admin-v2-people-business-20260905');
        await loadScript('/assets/js/admin-shell-v6.js?v=admin-shell-v6-20260905');
        await loadScript('/assets/js/admin-platform-v2.js?v=admin-platform-v2-20260905');
        await loadScript('/assets/js/admin-community-cleanup-v2.js?v=admin-community-cleanup-v2-20260905');
        await loadScript('/assets/js/admin-event-workspace-v1.js?v=admin-event-workspace-v1-20260905');
        await loadScript('/assets/js/admin-agent-v1.js?v=admin-agent-persistent-threads-v1-20260905');
        await loadScript('/assets/js/admin-agent-live-business-v1.js?v=admin-agent-live-business-v1-20260905');
        await loadScript('/assets/js/admin-agent-task-queue-v1.js?v=admin-agent-task-queue-v1-20260905');
        await loadScript('/assets/js/admin-agent-task-execution-v1.js?v=admin-agent-task-execution-v1-20260905');
        await loadScript('/assets/js/landing-event-images.js?v=landing-event-images-20260905');
        await loadScript('/assets/js/landing-network-v2.js?v=landing-network-v2-layout-20260905');
        await loadScript('/assets/js/public-mobile-header-v2.js?v=public-mobile-header-v2-20260905');
        await loadScript('/assets/js/invite-crm-v2.js?v=invite-crm-intelligence-v1-20260905');
        await loadScript('/assets/js/invite-profile-v2.js?v=invite-profile-v2-20260905');
    })().catch((error) => console.error('Coveted application scripts failed to load:', error));
})();