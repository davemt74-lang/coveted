(() => {
    'use strict';

    const section = document.querySelector('#upcoming-events .cv-landing-principles');
    if (!section) return;

    const sampleImages = new Map([
        ['rooftop social', '/assets/images/landing/hero-rooftop.png'],
        ['private dinner', '/assets/images/sample/events/sunset-dinner-hero.webp'],
        ['artist session', '/assets/images/sample/events/vinyl-and-cocktails-hero.webp'],
        ['mystery gathering', '/assets/images/sample/events/saturday-night-supper-club-hero.webp'],
    ]);

    section.querySelectorAll(':scope > article').forEach((card) => {
        const title = String(card.querySelector('h3')?.textContent || '').trim().toLowerCase();
        const meta = String(card.querySelector('p')?.textContent || '');
        const imageSrc = sampleImages.get(title);

        if (!imageSrc || !meta.includes('Preview ·') || card.querySelector('.cv-landing-event-media')) {
            return;
        }

        const media = document.createElement('div');
        media.className = 'cv-landing-event-media';

        const image = document.createElement('img');
        image.src = imageSrc;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';

        media.appendChild(image);
        card.prepend(media);
        card.classList.add('cv-landing-event-card', 'has-event-image');
    });
})();
