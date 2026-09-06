(() => {
    'use strict';

    const root = document.querySelector('[data-admin-agent]');
    if (!root) return;

    const starters = root.querySelector('.cv-admin-agent-starters');
    const form = root.querySelector('[data-agent-form]');
    const input = root.querySelector('[data-agent-input]');
    if (!starters || !form || !input) return;

    const queries = [
        {
            label: 'Compare city demand',
            prompt: 'Using the live business analytics in the server context, rank the cities with the strongest active CRM demand. Separate new, qualified and converted signals and tell me where Coveted should focus next. Do not invent data that is not in the live analytics.',
        },
        {
            label: 'Events needing attention',
            prompt: 'Using the live business analytics, list the future events that need Admin attention. Explain missing hosts, locations or invitations and prioritize what should be fixed first. Respect the rule that System Admin controls event creation and configuration.',
        },
        {
            label: 'Rank event interests',
            prompt: 'Using the live CRM interest-demand analytics, rank event interests by active demand and tell me which event concepts Coveted should plan around. Distinguish new, contacted and qualified demand where the analytics support it.',
        },
        {
            label: 'Review partner coverage',
            prompt: 'Using the live partner-coverage analytics, identify businesses missing locations, Business Admin coverage, active rewards or active campaigns. Prioritize the most important operational gaps and use the provided Admin routes when useful.',
        },
        {
            label: 'Compare this week',
            prompt: 'Compare Coveted activity in the last 7 days with the prior 7 days using the live audit analytics. Tell me what accelerated, slowed down or needs attention. Use only the aggregate categories supplied by the server.',
        },
        {
            label: 'Review host capacity',
            prompt: 'Review host capacity using the privacy-preserving live analytics. Tell me whether host coverage is sufficient for upcoming published events. Do not invent or name individual people; direct me to the People or Groups workspace if person-level selection is needed.',
        },
    ];

    queries.forEach(({ label, prompt }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.agentLiveBusinessStarter = '1';
        button.textContent = label;
        button.addEventListener('click', () => {
            if (input.disabled) return;
            input.value = prompt;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            form.requestSubmit();
        });
        starters.append(button);
    });
})();
