-- Coveted paid member + partner package catalog.
-- MySQL 8+. Run once after 20260907_service_packages_billing.sql.
-- This migration does not hard-code live paid prices. System Admin controls
-- package price, description, active state and entitlements in Service Packages.

UPDATE service_packages
SET name = 'Member',
    description = 'Core Coveted membership for invitations, gatherings, groups, benefits and the Social Concierge.',
    sort_order = 10,
    is_active = 1,
    is_default = 1
WHERE package_key = 'free';

UPDATE service_packages
SET name = 'Member+',
    description = 'Paid member service with advanced concierge, travel planning and eligible guided/group destination experiences.',
    sort_order = 20,
    is_active = 1
WHERE package_key = 'plus';

UPDATE service_packages
SET name = 'Partner',
    description = 'Business partner service for profiles, offers, event participation, customer history and basic partner results.',
    sort_order = 30,
    is_active = 1
WHERE package_key = 'partner';

INSERT INTO service_packages
    (package_key,name,description,monthly_price_cents,currency,sort_order,is_active,is_default)
VALUES
    ('partner_pro','Partner Pro','Advanced business partner service with campaigns, return-visit tracking, ROI reporting and AI partner insights.',NULL,'USD',40,1,0),
    ('organization','Organization','Multi-group/community service with membership lifecycle, conversion, referral, reporting and AI operations intelligence.',NULL,'USD',50,1,0)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- Billing metadata lives in the same dynamic entitlement table so Admin remains
-- the single source of truth for both package capabilities and public pricing.
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.subject','user',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.public','1',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'events.rsvp','1',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'groups.member','1',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'benefits.wallet','1',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'member.concierge.basic','1',1 FROM service_packages WHERE package_key='free'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;

INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.subject','user',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.public','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.core','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.plus','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'events.rsvp','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'groups.member','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'benefits.wallet','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'member.concierge.basic','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'member.concierge.advanced','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'travel.planning','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'travel.destination_events','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'travel.guided_groups','1',1 FROM service_packages WHERE package_key='plus'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;

INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.subject','business',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.public','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.workspace','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.profile','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.events','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.offers','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.crm.basic','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.results.basic','1',1 FROM service_packages WHERE package_key='partner'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;

INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.subject','business',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.public','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.workspace','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.profile','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.events','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.offers','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.crm.basic','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.results.basic','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.crm.advanced','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.results.advanced','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.campaigns','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.return_tracking','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.roi','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.ai_insights','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.sponsored_benefits','1',1 FROM service_packages WHERE package_key='partner_pro'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;

INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.subject','manual',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'billing.public','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.groups','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.membership_crm','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.guest_conversion','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.referrals','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.reporting','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
INSERT INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'organization.ai_operations','1',1 FROM service_packages WHERE package_key='organization'
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value),enabled=1;
