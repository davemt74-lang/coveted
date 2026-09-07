(() => {
    'use strict';
    const shell = document.querySelector('.cv-admin-app');
    if (!shell || shell.dataset.systemSample === '1') return;

    const params = new URLSearchParams(location.search);
    const eventRef = (params.get('event') || '').trim();
    if (!eventRef) return;

    const tabs = document.querySelector('.cv-admin-event-tabs');
    if (tabs && !tabs.querySelector('[data-event-invitation-waves-tab]')) {
        const link = document.createElement('a');
        link.dataset.eventInvitationWavesTab = '1';
        link.href = `/admin/event-invitation-waves.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Invitation Waves';
        if (location.pathname === '/admin/event-invitation-waves.php') link.classList.add('is-active');
        tabs.appendChild(link);
    }
    if (tabs && !tabs.querySelector('[data-event-invitation-execution-tab]')) {
        const link = document.createElement('a');
        link.dataset.eventInvitationExecutionTab = '1';
        link.href = `/admin/event-invitation-execution.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Wave Execution';
        if (location.pathname === '/admin/event-invitation-execution.php') link.classList.add('is-active');
        tabs.appendChild(link);
    }
    if (tabs && !tabs.querySelector('[data-event-rsvp-followup-tab]')) {
        const link = document.createElement('a');
        link.dataset.eventRsvpFollowupTab = '1';
        link.href = `/admin/event-rsvp-followup.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'RSVP Follow-Up';
        if (location.pathname === '/admin/event-rsvp-followup.php') link.classList.add('is-active');
        tabs.appendChild(link);
    }
    if (tabs && !tabs.querySelector('[data-event-communications-tab]')) {
        const link = document.createElement('a');
        link.dataset.eventCommunicationsTab = '1';
        link.href = `/admin/event-communications.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Communications';
        if (location.pathname === '/admin/event-communications.php') link.classList.add('is-active');
        tabs.appendChild(link);
    }

    const actions = document.querySelector('.cv-admin-event-top-actions,.cv-admin-page-head .cv-action-row');
    if (actions && !actions.querySelector('[data-event-invitation-waves-action]')) {
        const link = document.createElement('a');
        link.dataset.eventInvitationWavesAction = '1';
        link.className = 'cv-button cv-button-soft';
        link.href = `/admin/event-invitation-waves.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Invitation Waves';
        actions.prepend(link);
    }
    if (actions && !actions.querySelector('[data-event-invitation-execution-action]')) {
        const link = document.createElement('a');
        link.dataset.eventInvitationExecutionAction = '1';
        link.className = 'cv-button cv-button-soft';
        link.href = `/admin/event-invitation-execution.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Wave Execution';
        actions.prepend(link);
    }
    if (actions && !actions.querySelector('[data-event-rsvp-followup-action]')) {
        const link = document.createElement('a');
        link.dataset.eventRsvpFollowupAction = '1';
        link.className = 'cv-button cv-button-soft';
        link.href = `/admin/event-rsvp-followup.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'RSVP Follow-Up';
        actions.prepend(link);
    }
    if (actions && !actions.querySelector('[data-event-communications-action]')) {
        const link = document.createElement('a');
        link.dataset.eventCommunicationsAction = '1';
        link.className = 'cv-button cv-button-soft';
        link.href = `/admin/event-communications.php?event=${encodeURIComponent(eventRef)}`;
        link.textContent = 'Communications';
        actions.prepend(link);
    }
})();
