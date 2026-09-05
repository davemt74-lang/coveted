<?php
declare(strict_types=1);

require_once __DIR__ . '/invite_crm.php';

/**
 * Canonical launch-city seed used by public intake and Admin Cities.
 *
 * @return array<int,array{public_id:string,name:string,region:string,timezone:string,sort_order:int}>
 */
function coveted_nationwide_city_seed_rows(): array
{
    return [
        ['public_id' => 'city_san_francisco_ca', 'name' => 'San Francisco', 'region' => 'California', 'timezone' => 'America/Los_Angeles', 'sort_order' => 10],
        ['public_id' => 'city_san_diego_ca', 'name' => 'San Diego', 'region' => 'California', 'timezone' => 'America/Los_Angeles', 'sort_order' => 20],
        ['public_id' => 'city_phoenix_az', 'name' => 'Phoenix', 'region' => 'Arizona', 'timezone' => 'America/Phoenix', 'sort_order' => 30],
        ['public_id' => 'city_minneapolis_mn', 'name' => 'Minneapolis', 'region' => 'Minnesota', 'timezone' => 'America/Chicago', 'sort_order' => 40],
        ['public_id' => 'city_new_york_ny', 'name' => 'New York', 'region' => 'New York', 'timezone' => 'America/New_York', 'sort_order' => 50],
        ['public_id' => 'city_austin_tx', 'name' => 'Austin', 'region' => 'Texas', 'timezone' => 'America/Chicago', 'sort_order' => 60],
        ['public_id' => 'city_chicago_il', 'name' => 'Chicago', 'region' => 'Illinois', 'timezone' => 'America/Chicago', 'sort_order' => 70],
        ['public_id' => 'city_miami_fl', 'name' => 'Miami', 'region' => 'Florida', 'timezone' => 'America/New_York', 'sort_order' => 80],
        ['public_id' => 'city_nashville_tn', 'name' => 'Nashville', 'region' => 'Tennessee', 'timezone' => 'America/Chicago', 'sort_order' => 90],
        ['public_id' => 'city_denver_co', 'name' => 'Denver', 'region' => 'Colorado', 'timezone' => 'America/Denver', 'sort_order' => 100],
        ['public_id' => 'city_seattle_wa', 'name' => 'Seattle', 'region' => 'Washington', 'timezone' => 'America/Los_Angeles', 'sort_order' => 110],
        ['public_id' => 'city_atlanta_ga', 'name' => 'Atlanta', 'region' => 'Georgia', 'timezone' => 'America/New_York', 'sort_order' => 120],
    ];
}

/**
 * Replace the original Phoenix-metro seed with the nationwide launch list.
 * Legacy rows are archived rather than deleted so existing foreign keys and
 * historical CRM submissions remain valid.
 */
function coveted_sync_nationwide_cities(?PDO $pdo = null): void
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    $legacyPublicIds = [
        'city_scottsdale_az',
        'city_tempe_az',
        'city_mesa_az',
        'city_chandler_az',
        'city_gilbert_az',
    ];
    $legacyPlaceholders = implode(',', array_fill(0, count($legacyPublicIds), '?'));
    $archive = $pdo->prepare(
        "UPDATE cities
         SET status = 'archived', updated_at = UTC_TIMESTAMP()
         WHERE public_id IN ({$legacyPlaceholders}) AND status <> 'archived'"
    );
    $archive->execute($legacyPublicIds);

    $upsert = $pdo->prepare(
        "INSERT INTO cities (public_id,name,region,country,timezone,status,sort_order)
         VALUES (?, ?, ?, 'US', ?, 'active', ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            region = VALUES(region),
            country = 'US',
            timezone = VALUES(timezone),
            status = 'active',
            sort_order = VALUES(sort_order),
            updated_at = UTC_TIMESTAMP()"
    );

    foreach (coveted_nationwide_city_seed_rows() as $city) {
        $upsert->execute([
            $city['public_id'],
            $city['name'],
            $city['region'],
            $city['timezone'],
            $city['sort_order'],
        ]);
    }
}
