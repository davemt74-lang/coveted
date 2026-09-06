<?php
declare(strict_types=1);

require_once __DIR__ . '/partner_crm.php';

/**
 * Bulk, read-only Partner CRM context for the Admin Agent.
 *
 * This intentionally avoids the relationship-scoped UI readers in a loop so
 * every Agent Chat request does not repeatedly re-resolve the same venue
 * relationships. Raw email/phone fields are never selected into this context.
 *
 * @return array<string,mixed>
 */
function coveted_partner_crm_agent_context_v2(array $admin, int $limit = 12, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    if (!coveted_partner_crm_schema_available($pdo)) {
        return [
            'unavailable' => true,
            'counts' => ['relationships' => 0, 'contacts' => 0, 'open_followups' => 0, 'overdue_followups' => 0],
            'relationships' => [],
            'recent_activity' => [],
            'recommendations' => [],
            'privacy' => 'Partner CRM migration is not installed.',
            'action_policy' => 'Read-only Partner CRM intelligence.',
        ];
    }

    $limit = max(1, min(20, $limit));
    $businesses = array_slice(coveted_businesses_for_actor($admin), 0, 40);
    $businessIds = [];
    $relationshipRows = [];
    $relationshipIndex = [];

    foreach ($businesses as $business) {
        $businessId = (int)$business['id'];
        $businessIds[$businessId] = $business;
        foreach (coveted_venue_relationships_for_business($admin, $businessId) as $relationship) {
            if (count($relationshipRows) >= 60) {
                break 2;
            }
            $key = coveted_venue_relationship_key((int)$relationship['group_id'], (int)$relationship['location_id']);
            $relationship['business_id'] = $businessId;
            $relationship['business_ref'] = (string)$business['public_id'];
            $relationship['business_name'] = (string)$business['name'];
            $relationshipRows[$key] = $relationship;
            $relationshipIndex[$businessId . ':' . $key] = true;
        }
    }

    if (!$relationshipRows || !$businessIds) {
        return [
            'unavailable' => false,
            'counts' => ['relationships' => 0, 'contacts' => 0, 'open_followups' => 0, 'overdue_followups' => 0],
            'relationships' => [],
            'recent_activity' => [],
            'recommendations' => [],
            'privacy' => 'No Partner CRM relationship context is currently available.',
            'action_policy' => 'Read-only Partner CRM intelligence.',
        ];
    }

    $idList = implode(',', array_map('intval', array_keys($businessIds)));
    $owners = [];
    $stmt = $pdo->query(
        "SELECT prc.business_id,prc.group_id,prc.location_id,prc.relationship_summary,
                COALESCE(u.display_name,'') AS owner_name
         FROM partner_relationship_crm prc
         LEFT JOIN users u ON u.id=prc.relationship_owner_user_id
         WHERE prc.business_id IN ({$idList})"
    );
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['business_id'] . ':' . coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id']);
        if (isset($relationshipIndex[$key])) {
            $owners[$key] = $row;
        }
    }

    $contacts = [];
    $recentActivity = [];
    $stmt = $pdo->query(
        "SELECT pc.public_id,pc.business_id,pc.group_id,pc.location_id,pc.full_name,pc.role_title,
                pc.preferred_contact,pc.is_primary,pc.status,pc.created_at,pc.updated_at
         FROM partner_contacts pc
         WHERE pc.business_id IN ({$idList}) AND pc.status<>'archived'
         ORDER BY pc.is_primary DESC,pc.updated_at DESC,pc.id DESC
         LIMIT 500"
    );
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['business_id'] . ':' . coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id']);
        if (!isset($relationshipIndex[$key])) continue;
        $contacts[$key][] = $row;
        $rel = $relationshipRows[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] ?? null;
        if ($rel) {
            $recentActivity[] = [
                'kind' => 'contact',
                'business_ref' => (string)$rel['business_ref'],
                'business_name' => (string)$rel['business_name'],
                'group_ref' => (string)$rel['group_public_id'],
                'group_name' => (string)$rel['group_name'],
                'location_ref' => (string)$rel['location_public_id'],
                'location_name' => (string)$rel['location_name'],
                'contact_name' => (string)$row['full_name'],
                'summary' => 'Partner contact ' . ((string)$row['status'] === 'active' ? 'active' : 'inactive')
                    . (trim((string)$row['role_title']) !== '' ? ' · ' . (string)$row['role_title'] : '')
                    . ' · prefers ' . str_replace('_', ' ', (string)$row['preferred_contact']),
                'at' => (string)$row['updated_at'],
            ];
        }
    }

    $followups = [];
    $stmt = $pdo->query(
        "SELECT pf.public_id,pf.business_id,pf.group_id,pf.location_id,pf.title,pf.detail,pf.due_at,
                pf.priority,pf.status,pf.created_at,pf.updated_at,
                COALESCE(pc.full_name,'') AS contact_name,COALESCE(u.display_name,'Unassigned') AS assignee_name
         FROM partner_followups pf
         LEFT JOIN partner_contacts pc ON pc.id=pf.contact_id
         LEFT JOIN users u ON u.id=pf.assigned_user_id
         WHERE pf.business_id IN ({$idList})
         ORDER BY FIELD(pf.status,'open','completed','cancelled'),pf.due_at ASC,pf.id DESC
         LIMIT 500"
    );
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['business_id'] . ':' . coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id']);
        if (!isset($relationshipIndex[$key])) continue;
        $followups[$key][] = $row;
        $rel = $relationshipRows[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] ?? null;
        if ($rel) {
            $recentActivity[] = [
                'kind' => 'followup',
                'business_ref' => (string)$rel['business_ref'],
                'business_name' => (string)$rel['business_name'],
                'group_ref' => (string)$rel['group_public_id'],
                'group_name' => (string)$rel['group_name'],
                'location_ref' => (string)$rel['location_public_id'],
                'location_name' => (string)$rel['location_name'],
                'contact_name' => (string)$row['contact_name'],
                'summary' => ucfirst((string)$row['status']) . ' follow-up · ' . (string)$row['title']
                    . ' · due ' . (string)$row['due_at'] . ' · ' . (string)$row['assignee_name'],
                'at' => (string)$row['updated_at'],
            ];
        }
    }

    $lastInteractions = [];
    $stmt = $pdo->query(
        "SELECT pi.public_id,pi.business_id,pi.group_id,pi.location_id,pi.interaction_type,pi.direction,
                pi.subject,pi.summary,pi.occurred_at,COALESCE(pc.full_name,'') AS contact_name
         FROM partner_interactions pi
         LEFT JOIN partner_contacts pc ON pc.id=pi.contact_id
         WHERE pi.business_id IN ({$idList})
         ORDER BY pi.occurred_at DESC,pi.id DESC
         LIMIT 250"
    );
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['business_id'] . ':' . coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id']);
        if (!isset($relationshipIndex[$key])) continue;
        $lastInteractions[$key] ??= $row;
        $rel = $relationshipRows[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] ?? null;
        if ($rel) {
            $recentActivity[] = [
                'kind' => 'interaction',
                'business_ref' => (string)$rel['business_ref'],
                'business_name' => (string)$rel['business_name'],
                'group_ref' => (string)$rel['group_public_id'],
                'group_name' => (string)$rel['group_name'],
                'location_ref' => (string)$rel['location_public_id'],
                'location_name' => (string)$rel['location_name'],
                'contact_name' => (string)$row['contact_name'],
                'summary' => ucfirst(str_replace('_', ' ', (string)$row['interaction_type']))
                    . ' · ' . mb_substr((string)$row['summary'], 0, 180),
                'at' => (string)$row['occurred_at'],
            ];
        }
    }

    $stmt = $pdo->query(
        "SELECT pn.business_id,pn.group_id,pn.location_id,pn.note_type,pn.body,pn.created_at,
                COALESCE(pc.full_name,'') AS contact_name
         FROM partner_notes pn
         LEFT JOIN partner_contacts pc ON pc.id=pn.contact_id
         WHERE pn.business_id IN ({$idList})
         ORDER BY pn.created_at DESC,pn.id DESC
         LIMIT 80"
    );
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['business_id'] . ':' . coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id']);
        if (!isset($relationshipIndex[$key])) continue;
        $rel = $relationshipRows[coveted_venue_relationship_key((int)$row['group_id'], (int)$row['location_id'])] ?? null;
        if (!$rel) continue;
        $recentActivity[] = [
            'kind' => 'note',
            'business_ref' => (string)$rel['business_ref'],
            'business_name' => (string)$rel['business_name'],
            'group_ref' => (string)$rel['group_public_id'],
            'group_name' => (string)$rel['group_name'],
            'location_ref' => (string)$rel['location_public_id'],
            'location_name' => (string)$rel['location_name'],
            'contact_name' => (string)$row['contact_name'],
            'summary' => ucfirst((string)$row['note_type']) . ' note · ' . mb_substr((string)$row['body'], 0, 180),
            'at' => (string)$row['created_at'],
        ];
    }

    $relationships = [];
    $recommendations = [];
    $totalContacts = 0;
    $openFollowups = 0;
    $overdueFollowups = 0;
    $now = time();

    foreach ($relationshipRows as $rel) {
        $key = (int)$rel['business_id'] . ':' . coveted_venue_relationship_key((int)$rel['group_id'], (int)$rel['location_id']);
        $activeContacts = array_values(array_filter(
            (array)($contacts[$key] ?? []),
            static fn(array $contact): bool => (string)$contact['status'] === 'active'
        ));
        $primary = null;
        foreach ($activeContacts as $contact) {
            if ((int)$contact['is_primary'] === 1) {
                $primary = $contact;
                break;
            }
        }

        $open = array_values(array_filter(
            (array)($followups[$key] ?? []),
            static fn(array $followup): bool => (string)$followup['status'] === 'open'
        ));
        usort($open, static fn(array $a, array $b): int => strcmp((string)$a['due_at'], (string)$b['due_at']));
        $overdue = array_values(array_filter($open, static fn(array $followup): bool => strtotime((string)$followup['due_at']) < time()));
        $dueSoon = array_values(array_filter($open, static fn(array $followup): bool =>
            strtotime((string)$followup['due_at']) >= time() && strtotime((string)$followup['due_at']) <= time() + 604800
        ));

        $totalContacts += count($activeContacts);
        $openFollowups += count($open);
        $overdueFollowups += count($overdue);
        $owner = (array)($owners[$key] ?? []);
        $lastInteraction = $lastInteractions[$key] ?? null;
        $href = '/partner-profile.php?business=' . rawurlencode((string)$rel['business_ref'])
            . '&group=' . rawurlencode((string)$rel['group_public_id'])
            . '&location=' . rawurlencode((string)$rel['location_public_id']);

        $relationships[] = [
            'business_ref' => (string)$rel['business_ref'],
            'business_name' => (string)$rel['business_name'],
            'group_ref' => (string)$rel['group_public_id'],
            'group_name' => (string)$rel['group_name'],
            'location_ref' => (string)$rel['location_public_id'],
            'location_name' => (string)$rel['location_name'],
            'relationship_status' => (string)$rel['relationship_status'],
            'owner_name' => (string)($owner['owner_name'] ?? ''),
            'relationship_summary' => mb_substr((string)($owner['relationship_summary'] ?? ''), 0, 240),
            'primary_contact' => $primary ? [
                'name' => (string)$primary['full_name'],
                'role' => (string)($primary['role_title'] ?? ''),
                'preferred_contact' => (string)$primary['preferred_contact'],
            ] : null,
            'contact_count' => count($activeContacts),
            'open_followups' => count($open),
            'overdue_followups' => count($overdue),
            'next_followup' => $open ? [
                'title' => (string)$open[0]['title'],
                'due_at' => (string)$open[0]['due_at'],
                'contact_name' => (string)($open[0]['contact_name'] ?? ''),
                'assignee_name' => (string)($open[0]['assignee_name'] ?? 'Unassigned'),
            ] : null,
            'last_interaction' => $lastInteraction ? [
                'type' => (string)$lastInteraction['interaction_type'],
                'contact_name' => (string)($lastInteraction['contact_name'] ?? ''),
                'summary' => mb_substr((string)$lastInteraction['summary'], 0, 180),
                'at' => (string)$lastInteraction['occurred_at'],
            ] : null,
            'href' => $href,
        ];

        if ($overdue) {
            $followup = $overdue[0];
            $recommendations[] = [
                'priority' => 1,
                'key' => 'partner-crm-overdue-' . (string)$followup['public_id'],
                'kind' => 'partner_followup_overdue',
                'title' => 'Follow up with ' . (trim((string)$followup['contact_name']) !== '' ? (string)$followup['contact_name'] : (string)$rel['location_name']),
                'detail' => (string)$followup['title'] . '. This partner follow-up is overdue; review the Partner Profile and record the outcome instead of letting the relationship go quiet.',
                'evidence' => 'Due ' . (string)$followup['due_at'] . ' · ' . ucfirst((string)$followup['priority'])
                    . ' priority · ' . (string)$rel['business_name'] . ' / ' . (string)$rel['location_name'] . '.',
                'href' => $href,
            ];
        } elseif ($dueSoon) {
            $followup = $dueSoon[0];
            $recommendations[] = [
                'priority' => 2,
                'key' => 'partner-crm-due-' . (string)$followup['public_id'],
                'kind' => 'partner_followup_due',
                'title' => 'Upcoming partner follow-up · ' . (string)$followup['title'],
                'detail' => 'A scheduled partner follow-up is due within seven days. Open the Partner Profile for the relationship context, primary contact and recent activity.',
                'evidence' => 'Due ' . (string)$followup['due_at'] . ' · ' . (string)$rel['business_name'] . ' / ' . (string)$rel['location_name'] . '.',
                'href' => $href,
            ];
        }

        $established = in_array((string)$rel['relationship_status'], ['partner','preferred_partner','home_venue'], true)
            || (int)$rel['completed_events'] >= 2;
        if ($established && !$activeContacts) {
            $recommendations[] = [
                'priority' => 2,
                'key' => 'partner-crm-contact-' . (int)$rel['business_id'] . '-' . (int)$rel['group_id'] . '-' . (int)$rel['location_id'],
                'kind' => 'partner_contact_missing',
                'title' => 'Add a primary contact for this partner',
                'detail' => 'The relationship has established event history but no active Partner CRM contact. Add the real owner, manager, event lead or marketing contact so future event and perk work has a human relationship attached.',
                'evidence' => (int)$rel['completed_events'] . ' completed events · ' . (int)$rel['verified_visits'] . ' verified visits · 0 active partner contacts.',
                'href' => $href,
            ];
        }
        if ($established && trim((string)($owner['owner_name'] ?? '')) === '') {
            $recommendations[] = [
                'priority' => 3,
                'key' => 'partner-crm-owner-' . (int)$rel['business_id'] . '-' . (int)$rel['group_id'] . '-' . (int)$rel['location_id'],
                'kind' => 'partner_owner_missing',
                'title' => 'Assign a Coveted relationship owner',
                'detail' => 'This established partner relationship has no internal Coveted owner. Assign one System Admin so follow-ups and partner history have clear accountability.',
                'evidence' => (string)$rel['business_name'] . ' / ' . (string)$rel['location_name'] . ' · ' . (string)$rel['relationship_status'] . '.',
                'href' => $href,
            ];
        }
    }

    usort($recommendations, static function (array $a, array $b): int {
        $priority = (int)$a['priority'] <=> (int)$b['priority'];
        return $priority !== 0 ? $priority : strcmp((string)$a['key'], (string)$b['key']);
    });
    usort($relationships, static fn(array $a, array $b): int =>
        ((int)$b['overdue_followups'] <=> (int)$a['overdue_followups'])
        ?: strcmp((string)$a['business_name'], (string)$b['business_name'])
    );
    usort($recentActivity, static fn(array $a, array $b): int => strcmp((string)$b['at'], (string)$a['at']));

    return [
        'unavailable' => false,
        'counts' => [
            'relationships' => count($relationships),
            'contacts' => $totalContacts,
            'open_followups' => $openFollowups,
            'overdue_followups' => $overdueFollowups,
        ],
        'relationships' => array_slice($relationships, 0, $limit),
        'recent_activity' => array_slice($recentActivity, 0, 16),
        'recommendations' => array_slice($recommendations, 0, 20),
        'privacy' => 'Agent context includes partner contact names, roles, preferred contact method, follow-up state, relationship summaries and concise CRM activity. Raw partner email addresses and phone numbers are intentionally not selected into this broad LLM context.',
        'action_policy' => 'Partner CRM intelligence is read-only for the Agent. Contacts, notes, interactions, assignments and follow-up state change only through explicit authorized Coveted actions.',
    ];
}
