<?php
declare(strict_types=1);

require_once __DIR__ . '/system_sample_data.php';

/**
 * Member preview is now a projection of the full Coveted sample system.
 * The legacy member-only toggle remains supported for existing installs;
 * the new full-system toggle automatically enables the same member preview.
 */
function coveted_member_sample_mode(array $user, ?PDO $pdo = null): bool
{
    if (!coveted_is_system_admin($user)) {
        return false;
    }

    return coveted_system_sample_mode($user, $pdo)
        || coveted_site_setting_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, false, $pdo);
}

function coveted_member_sample_event_start(int $daysAhead, int $hour, int $minute = 0): string
{
    return coveted_system_sample_time('+' . max(1, $daysAhead) . ' days', $hour, $minute);
}

function coveted_member_sample_past_event_start(int $daysAgo, int $hour, int $minute = 0): string
{
    return coveted_system_sample_time('-' . max(1, $daysAgo) . ' days', $hour, $minute);
}

/** @return array<string,mixed> */
function coveted_member_sample_data(): array
{
    return (array)coveted_system_sample_data()['member'];
}
