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
        await loadScript('/assets/js/member-v2.js?v=member-v2-20260905');
    })().catch((error) => console.error('Coveted application scripts failed to load:', error));
})();
