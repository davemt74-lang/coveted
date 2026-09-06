-- Coveted Partner Profile CRM
-- Adds reusable business profile identity plus relationship-scoped CRM records.
-- Operational event/reward/perk history remains in canonical existing tables.

CREATE TABLE IF NOT EXISTS business_profiles (
  business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  logo_url VARCHAR(700) NULL,
  cover_url VARCHAR(700) NULL,
  website_url VARCHAR(700) NULL,
  phone VARCHAR(80) NULL,
  category_label VARCHAR(160) NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_business_profiles_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_business_profiles_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_relationship_crm (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  relationship_owner_user_id BIGINT UNSIGNED NULL,
  relationship_summary TEXT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partner_relationship_crm (group_id,location_id),
  KEY idx_partner_relationship_crm_business (business_id,updated_at),
  KEY idx_partner_relationship_crm_owner (relationship_owner_user_id,updated_at),
  CONSTRAINT fk_partner_relationship_crm_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_relationship_crm_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_relationship_crm_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_relationship_crm_owner FOREIGN KEY (relationship_owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_relationship_crm_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  role_title VARCHAR(180) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(80) NULL,
  preferred_contact ENUM('email','phone','text','in_person','other') NOT NULL DEFAULT 'email',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_partner_contacts_relationship (group_id,location_id,status,is_primary),
  KEY idx_partner_contacts_business (business_id,status,updated_at),
  CONSTRAINT fk_partner_contacts_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_contacts_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_contacts_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_contacts_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  note_type ENUM('relationship','contact','timeline') NOT NULL DEFAULT 'relationship',
  body TEXT NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_partner_notes_relationship (group_id,location_id,created_at),
  KEY idx_partner_notes_contact (contact_id,created_at),
  CONSTRAINT fk_partner_notes_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_notes_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_notes_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_notes_contact FOREIGN KEY (contact_id) REFERENCES partner_contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_notes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_interactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  interaction_type ENUM('call','email','text','meeting','in_person','other') NOT NULL DEFAULT 'other',
  direction ENUM('outbound','inbound','internal') NOT NULL DEFAULT 'outbound',
  subject VARCHAR(190) NULL,
  summary TEXT NOT NULL,
  occurred_at DATETIME NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_partner_interactions_relationship (group_id,location_id,occurred_at),
  KEY idx_partner_interactions_contact (contact_id,occurred_at),
  CONSTRAINT fk_partner_interactions_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_interactions_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_interactions_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_interactions_contact FOREIGN KEY (contact_id) REFERENCES partner_contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_interactions_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_followups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  detail TEXT NULL,
  due_at DATETIME NOT NULL,
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  completed_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_partner_followups_due (status,due_at,priority),
  KEY idx_partner_followups_relationship (group_id,location_id,status,due_at),
  KEY idx_partner_followups_assignee (assigned_user_id,status,due_at),
  CONSTRAINT fk_partner_followups_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_followups_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_followups_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_followups_contact FOREIGN KEY (contact_id) REFERENCES partner_contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_followups_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_partner_followups_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;