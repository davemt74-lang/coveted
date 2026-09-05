-- Coveted canonical pre-install schema
-- MySQL 8+. Until the first production install this file is the single
-- database source of truth; do not add compatibility migrations or aliases.
-- All DATETIME values are stored in UTC. Timezone columns contain IANA names
-- used only for local presentation.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  status ENUM('active','invited','suspended','deleted') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_status_created (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL UNIQUE,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_auth_attempts_blocked (blocked_until),
  KEY idx_auth_attempts_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL UNIQUE,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_claim_attempts_blocked (blocked_until),
  KEY idx_claim_attempts_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only platform-wide capabilities belong in user_roles. Business Admin,
-- Group Admin and Artist ownership/management are resource-scoped relations.
CREATE TABLE IF NOT EXISTS user_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  role_key ENUM('attendee','attendee_host','artist_partner','system_admin') NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_role (user_id,role_key),
  KEY idx_user_roles_role_user (role_key,user_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  role_key ENUM('attendee_host','artist_partner') NOT NULL,
  request_note TEXT NULL,
  status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_role_requests_status_created (status,created_at),
  KEY idx_role_requests_user_role_status (user_id,role_key,status),
  CONSTRAINT fk_role_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  bio TEXT NULL,
  city VARCHAR(160) NULL,
  avatar_url VARCHAR(700) NULL,
  cover_url VARCHAR(700) NULL,
  interests_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  city VARCHAR(160) NULL,
  visibility ENUM('private','invite_only','unlisted') NOT NULL DEFAULT 'invite_only',
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_social_groups_status_updated (status,updated_at),
  CONSTRAINT fk_social_groups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  group_role ENUM('guest','member','host','group_admin') NOT NULL DEFAULT 'member',
  membership_status ENUM('invited','active','away','left','removed') NOT NULL DEFAULT 'active',
  invited_by BIGINT UNSIGNED NULL,
  joined_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_group_member (group_id,user_id),
  KEY idx_group_memberships_user_status (user_id,membership_status),
  KEY idx_group_memberships_group_status_role (group_id,membership_status,group_role),
  CONSTRAINT fk_group_memberships_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_memberships_inviter FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  group_id BIGINT UNSIGNED NOT NULL,
  inviter_user_id BIGINT UNSIGNED NOT NULL,
  invitee_email VARCHAR(255) NULL,
  invitee_user_id BIGINT UNSIGNED NULL,
  invite_token_hash VARCHAR(255) NOT NULL,
  status ENUM('pending','accepted','declined','expired','revoked') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NULL,
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_group_invites_email_status (group_id,invitee_email,status,expires_at),
  KEY idx_group_invites_user_status (invitee_user_id,status,created_at),
  CONSTRAINT fk_group_invitations_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_invitations_inviter FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_group_invitations_invitee FOREIGN KEY (invitee_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_guest_passes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  group_id BIGINT UNSIGNED NOT NULL,
  issued_to_user_id BIGINT UNSIGNED NOT NULL,
  issued_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('available','reserved','used','expired','revoked') NOT NULL DEFAULT 'available',
  guest_email VARCHAR(255) NULL,
  guest_user_id BIGINT UNSIGNED NULL,
  invitation_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_group_guest_passes_owner (group_id,issued_to_user_id,status,expires_at),
  UNIQUE KEY uq_group_guest_pass_invitation (invitation_id),
  CONSTRAINT fk_group_guest_pass_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_guest_pass_owner FOREIGN KEY (issued_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_guest_pass_issuer FOREIGN KEY (issued_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_group_guest_pass_guest FOREIGN KEY (guest_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_group_guest_pass_invitation FOREIGN KEY (invitation_id) REFERENCES group_invitations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_admin_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  group_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  context_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_group_admin_events_group_created (group_id,created_at),
  KEY idx_group_admin_events_actor_created (actor_user_id,created_at),
  CONSTRAINT fk_group_admin_events_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_admin_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_group_admin_events_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  status ENUM('prospective','active','paused','archived') NOT NULL DEFAULT 'prospective',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_businesses_status_updated (status,updated_at),
  CONSTRAINT fk_businesses_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_admin (business_id,user_id),
  KEY idx_business_admins_user (user_id,business_id),
  CONSTRAINT fk_business_admins_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_business_admins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  address1 VARCHAR(255) NULL,
  address2 VARCHAR(255) NULL,
  city VARCHAR(160) NULL,
  region VARCHAR(160) NULL,
  postal_code VARCHAR(40) NULL,
  country VARCHAR(2) NOT NULL DEFAULT 'US',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  capacity INT UNSIGNED NULL,
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_locations_business_status (business_id,status),
  KEY idx_locations_city_status (city,status),
  CONSTRAINT fk_locations_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_claim_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  code_type ENUM('location','employee') NOT NULL,
  label VARCHAR(180) NOT NULL,
  code_lookup CHAR(64) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_claim_code_lookup (business_id,code_lookup),
  KEY idx_business_claim_codes_business_status (business_id,status),
  KEY idx_business_claim_codes_location_status (location_id,status),
  CONSTRAINT fk_business_claim_codes_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_business_claim_codes_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_business_claim_codes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_claim_code_location_scope CHECK (code_type <> 'location' OR location_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  artist_name VARCHAR(180) NOT NULL,
  bio TEXT NULL,
  avatar_url VARCHAR(700) NULL,
  cover_url VARCHAR(700) NULL,
  links_json JSON NULL,
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_artist_profiles_owner_status (owner_user_id,status),
  KEY idx_artist_profiles_status_updated (status,updated_at),
  CONSTRAINT fk_artist_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_role ENUM('owner','manager','member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artist_member (artist_id,user_id),
  KEY idx_artist_members_user (user_id,artist_id),
  CONSTRAINT fk_artist_members_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_artist_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  group_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  event_type ENUM('regular','mystery','private_table','member_plus_one','session') NOT NULL DEFAULT 'regular',
  audience ENUM('group','invitation_only') NOT NULL DEFAULT 'group',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  capacity INT UNSIGNED NULL,
  plus_one_allowed TINYINT(1) NOT NULL DEFAULT 0,
  location_visibility ENUM('immediate','scheduled_reveal','host_only') NOT NULL DEFAULT 'immediate',
  status ENUM('draft','published','closed','completed','cancelled') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_events_group_start (group_id,starts_at),
  KEY idx_events_group_status_start (group_id,status,starts_at),
  KEY idx_events_group_audience_status_start (group_id,audience,status,starts_at),
  KEY idx_events_status_start (status,starts_at),
  CONSTRAINT fk_events_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_event_time_order CHECK (ends_at IS NULL OR ends_at > starts_at),
  CONSTRAINT chk_event_capacity CHECK (capacity IS NULL OR capacity > 0),
  CONSTRAINT chk_event_plus_one CHECK (plus_one_allowed IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_hosts (
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  host_role ENUM('lead','cohost','checkin') NOT NULL DEFAULT 'cohost',
  PRIMARY KEY (event_id,user_id),
  KEY idx_event_hosts_user (user_id,event_id),
  CONSTRAINT fk_event_hosts_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_hosts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V1 intentionally supports one canonical location per event.
CREATE TABLE IF NOT EXISTS event_locations (
  event_id BIGINT UNSIGNED PRIMARY KEY,
  location_id BIGINT UNSIGNED NULL,
  private_location_label VARCHAR(255) NULL,
  reveal_notes TEXT NULL,
  KEY idx_event_locations_location (location_id,event_id),
  CONSTRAINT fk_event_locations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_locations_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_artists (
  event_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NOT NULL,
  appearance_type ENUM('featured','support','dj','session','mystery') NOT NULL DEFAULT 'featured',
  PRIMARY KEY (event_id,artist_id),
  KEY idx_event_artists_artist (artist_id,event_id),
  CONSTRAINT fk_event_artists_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_artists_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  invited_by BIGINT UNSIGNED NULL,
  invite_type ENUM('member','guest','plus_one','standby') NOT NULL DEFAULT 'member',
  status ENUM('pending','accepted','declined','expired','revoked') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_invitation (event_id,user_id),
  KEY idx_event_invitations_user_status (user_id,status,event_id),
  CONSTRAINT fk_event_invitations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_invitations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_invitations_inviter FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_rsvps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  response ENUM('attending','declined','waitlist') NOT NULL,
  guest_count INT UNSIGNED NOT NULL DEFAULT 0,
  responded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_rsvp (event_id,user_id),
  KEY idx_event_rsvps_user_response (user_id,response,event_id),
  KEY idx_event_rsvps_waitlist (event_id,response,responded_at,id),
  CONSTRAINT fk_event_rsvps_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_rsvps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_event_rsvp_guest_count CHECK (guest_count <= 1),
  CONSTRAINT chk_event_rsvp_declined_guest CHECK (response <> 'declined' OR guest_count = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('checked_in','attended','no_show','left_early') NOT NULL DEFAULT 'checked_in',
  checked_in_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_attendance (event_id,user_id),
  KEY idx_event_attendance_user_status (user_id,status,event_id),
  CONSTRAINT fk_event_attendance_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_attendance_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  response ENUM('yes','maybe','no') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_feedback_member (event_id,user_id),
  KEY idx_event_feedback_event_response (event_id,response),
  CONSTRAINT fk_event_feedback_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_mystery_reveals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  reveal_at DATETIME NOT NULL,
  reveal_type ENUM('area','experience','instructions','location','artist','custom') NOT NULL,
  title VARCHAR(180) NULL,
  content TEXT NOT NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mystery_reveals_event_time (event_id,reveal_at),
  KEY idx_mystery_reveals_due (reveal_at,notified_at),
  CONSTRAINT fk_mystery_reveals_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venue_relationships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  relationship_status ENUM('new','event_venue','partner','preferred_partner','home_venue') NOT NULL DEFAULT 'new',
  partner_since DATETIME NULL,
  benefits_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mystery_events_enabled TINYINT(1) NOT NULL DEFAULT 0,
  first_event_at DATETIME NULL,
  last_event_at DATETIME NULL,
  notes TEXT NULL,
  UNIQUE KEY uq_group_location_relationship (group_id,location_id),
  KEY idx_venue_relationships_location_status (location_id,relationship_status),
  CONSTRAINT fk_venue_relationships_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_venue_relationships_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_group_relationships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NOT NULL,
  relationship_status ENUM('new','featured','partner','preferred_partner') NOT NULL DEFAULT 'new',
  first_event_at DATETIME NULL,
  last_event_at DATETIME NULL,
  notes TEXT NULL,
  UNIQUE KEY uq_group_artist_relationship (group_id,artist_id),
  KEY idx_artist_group_artist_status (artist_id,relationship_status),
  CONSTRAINT fk_artist_group_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_artist_group_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  owner_type ENUM('platform','group','business','artist') NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  artist_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  reward_type ENUM('credit','free_item','discount','perk','access','service','audio','video','media_pack','experience','custom') NOT NULL DEFAULT 'custom',
  claim_mode ENUM('none','location_code') NOT NULL DEFAULT 'none',
  value_amount DECIMAL(12,2) NULL,
  value_text VARCHAR(255) NULL,
  cover_url VARCHAR(700) NULL,
  claim_rules_json JSON NULL,
  redemption_rules_json JSON NULL,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reward_templates_owner_status (owner_type,status),
  KEY idx_reward_templates_group_status (group_id,status),
  KEY idx_reward_templates_business_status (business_id,status),
  KEY idx_reward_templates_artist_status (artist_id,status),
  CONSTRAINT fk_reward_templates_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_reward_templates_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_reward_templates_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_reward_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_reward_template_owner CHECK (
    (owner_type = 'platform' AND group_id IS NULL AND business_id IS NULL AND artist_id IS NULL) OR
    (owner_type = 'group' AND group_id IS NOT NULL AND business_id IS NULL AND artist_id IS NULL) OR
    (owner_type = 'business' AND group_id IS NULL AND business_id IS NOT NULL AND artist_id IS NULL) OR
    (owner_type = 'artist' AND group_id IS NULL AND business_id IS NULL AND artist_id IS NOT NULL)
  ),
  CONSTRAINT chk_reward_claim_mode CHECK (claim_mode <> 'location_code' OR owner_type = 'business'),
  CONSTRAINT chk_reward_template_time CHECK (expires_at IS NULL OR starts_at IS NULL OR expires_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reward_template_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('audio','video','image','file') NOT NULL,
  title VARCHAR(190) NULL,
  media_url VARCHAR(1000) NOT NULL,
  mime_type VARCHAR(120) NULL,
  duration_seconds INT UNSIGNED NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reward_media_template_sort (reward_template_id,sort_order,id),
  CONSTRAINT fk_reward_media_template FOREIGN KEY (reward_template_id) REFERENCES reward_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  owner_type ENUM('platform','group','business','artist') NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  artist_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  reward_template_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  campaign_type ENUM('attendance','event_completion','return_visit','guest_return','random_reward','mystery_unlock','membership','birthday','manual','custom') NOT NULL DEFAULT 'manual',
  trigger_key ENUM('attendance','completion','return_visit','guest_return','random_reward','mystery_unlock','membership','birthday','manual') NOT NULL DEFAULT 'manual',
  quantity_limit INT UNSIGNED NULL,
  per_user_limit INT UNSIGNED NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_campaigns_owner_status (owner_type,status),
  KEY idx_campaigns_trigger_status (trigger_key,status,starts_at,ends_at),
  KEY idx_campaigns_reward (reward_template_id,status),
  KEY idx_campaigns_group_status (group_id,status),
  KEY idx_campaigns_business_status (business_id,status),
  KEY idx_campaigns_artist_status (artist_id,status),
  CONSTRAINT fk_campaigns_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_campaigns_reward FOREIGN KEY (reward_template_id) REFERENCES reward_templates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_campaigns_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_campaign_owner CHECK (
    (owner_type = 'platform' AND group_id IS NULL AND business_id IS NULL AND artist_id IS NULL) OR
    (owner_type = 'group' AND group_id IS NOT NULL AND business_id IS NULL AND artist_id IS NULL) OR
    (owner_type = 'business' AND group_id IS NULL AND business_id IS NOT NULL AND artist_id IS NULL) OR
    (owner_type = 'artist' AND group_id IS NULL AND business_id IS NULL AND artist_id IS NOT NULL)
  ),
  CONSTRAINT chk_campaign_location_owner CHECK (location_id IS NULL OR owner_type = 'business'),
  CONSTRAINT chk_campaign_time CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_event_links (
  campaign_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id,event_id),
  KEY idx_campaign_event_links_event (event_id,campaign_id),
  CONSTRAINT fk_campaign_event_links_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaign_event_links_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_issuances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  reward_template_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  location_id BIGINT UNSIGNED NULL,
  artist_id BIGINT UNSIGNED NULL,
  status ENUM('issued','viewed','claimed','expired','cancelled') NOT NULL DEFAULT 'issued',
  idempotency_key VARCHAR(190) NULL,
  metadata_json JSON NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  viewed_at DATETIME NULL,
  claimed_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reward_issuance_idempotency (idempotency_key),
  KEY idx_reward_issuances_user_status (user_id,status,issued_at),
  KEY idx_reward_issuances_campaign_user (campaign_id,user_id,status),
  KEY idx_reward_issuances_event_user (event_id,user_id),
  KEY idx_reward_issuances_expiry (status,expires_at),
  CONSTRAINT fk_reward_issuances_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reward_issuances_template FOREIGN KEY (reward_template_id) REFERENCES reward_templates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reward_issuances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reward_issuances_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_reward_issuances_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_reward_issuances_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reward_claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  reward_issuance_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  claim_code_id BIGINT UNSIGNED NOT NULL,
  claim_code_type ENUM('location','employee') NOT NULL,
  claim_code_label VARCHAR(180) NOT NULL,
  status ENUM('claimed','refunded') NOT NULL DEFAULT 'claimed',
  active_reward_issuance_id BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN status = 'claimed' THEN reward_issuance_id ELSE NULL END
  ) STORED,
  claim_method ENUM('location_code') NOT NULL DEFAULT 'location_code',
  metadata_json JSON NULL,
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  refunded_at DATETIME NULL,
  refunded_by_user_id BIGINT UNSIGNED NULL,
  refund_reason VARCHAR(500) NULL,
  UNIQUE KEY uq_reward_claims_active_issuance (active_reward_issuance_id),
  KEY idx_reward_claims_issuance_status (reward_issuance_id,status,claimed_at),
  KEY idx_reward_claims_location_time (location_id,claimed_at),
  KEY idx_reward_claims_code_time (claim_code_id,claimed_at),
  KEY idx_reward_claims_refunded (status,refunded_at),
  CONSTRAINT fk_reward_claims_issuance FOREIGN KEY (reward_issuance_id) REFERENCES reward_issuances(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reward_claims_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reward_claims_code FOREIGN KEY (claim_code_id) REFERENCES business_claim_codes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reward_claims_refunder FOREIGN KEY (refunded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  reward_issuance_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  activity_type ENUM('campaign_triggered','reward_issued','reward_viewed','reward_claimed','reward_refunded','reward_expired') NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_campaign_activity_campaign_time (campaign_id,created_at),
  KEY idx_campaign_activity_user_time (user_id,created_at),
  KEY idx_campaign_activity_event_time (event_id,created_at),
  CONSTRAINT fk_campaign_activity_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_campaign_activity_issuance FOREIGN KEY (reward_issuance_id) REFERENCES reward_issuances(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaign_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaign_activity_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconnect_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  requester_user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','mutual','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  matched_at DATETIME NULL,
  UNIQUE KEY uq_reconnect_pair (event_id,requester_user_id,target_user_id),
  KEY idx_reconnect_target_status (target_user_id,status,event_id),
  KEY idx_reconnect_requester_status (requester_user_id,status,event_id),
  CONSTRAINT fk_reconnect_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_reconnect_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reconnect_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_reconnect_distinct_users CHECK (requester_user_id <> target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NULL,
  action_url VARCHAR(700) NULL,
  payload_json JSON NULL,
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  dedupe_key VARCHAR(190) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notifications_user_dedupe (user_id,dedupe_key),
  KEY idx_notifications_user_read (user_id,read_at,created_at),
  KEY idx_notifications_type_created (notification_type,created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id VARCHAR(80) NOT NULL UNIQUE,
  client_type ENUM('pwa','ios','android') NOT NULL,
  transport ENUM('web_push','apns','fcm') NOT NULL,
  endpoint VARCHAR(1200) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(255) NULL,
  auth_secret VARCHAR(255) NULL,
  content_encoding VARCHAR(40) NULL,
  status ENUM('active','disabled','invalid') NOT NULL DEFAULT 'active',
  active_endpoint_hash CHAR(64) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN endpoint_hash ELSE NULL END) STORED,
  device_label VARCHAR(120) NULL,
  user_agent VARCHAR(500) NULL,
  last_seen_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_devices_active_endpoint (transport,active_endpoint_hash),
  KEY idx_notification_devices_user_status (user_id,status,updated_at),
  KEY idx_notification_devices_endpoint (transport,endpoint_hash),
  CONSTRAINT fk_notification_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_notification_device_transport CHECK (
    (client_type = 'pwa' AND transport = 'web_push') OR
    (client_type = 'ios' AND transport IN ('apns','fcm')) OR
    (client_type = 'android' AND transport = 'fcm')
  ),
  CONSTRAINT chk_notification_device_web_keys CHECK (
    transport <> 'web_push' OR (p256dh IS NOT NULL AND auth_secret IS NOT NULL AND content_encoding IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  transport ENUM('web_push','apns','fcm') NOT NULL,
  status ENUM('pending','sending','sent','failed','permanent_failure','cancelled') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  sent_at DATETIME NULL,
  response_code INT NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_delivery (notification_id,device_id),
  KEY idx_notification_deliveries_queue (transport,status,next_attempt_at,id),
  KEY idx_notification_deliveries_device (device_id,status,updated_at),
  CONSTRAINT fk_notification_deliveries_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_deliveries_device FOREIGN KEY (device_id) REFERENCES notification_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(190) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_type_time (event_type,created_at),
  KEY idx_audit_actor_time (actor_user_id,created_at),
  KEY idx_audit_entity_time (entity_type,entity_id,created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;