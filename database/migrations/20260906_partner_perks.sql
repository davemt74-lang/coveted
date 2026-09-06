-- Partner Offers / Perks
-- Standing group x venue benefits backed by canonical Business campaigns/rewards.
-- Safe to import once on an existing Coveted install.

CREATE TABLE IF NOT EXISTS partner_perks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  perk_type ENUM('member_discount','member_perk','preferred_access','surprise_reward','return_visit') NOT NULL DEFAULT 'member_perk',
  distribution_mode ENUM('once','monthly','manual') NOT NULL DEFAULT 'once',
  status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partner_perk_relationship_campaign (group_id,location_id,campaign_id),
  KEY idx_partner_perks_business_status (business_id,status,updated_at),
  KEY idx_partner_perks_relationship_status (group_id,location_id,status,updated_at),
  KEY idx_partner_perks_campaign_status (campaign_id,status),
  KEY idx_partner_perks_due (status,distribution_mode,starts_at,ends_at),
  CONSTRAINT fk_partner_perks_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_perks_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_perks_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_perks_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_partner_perks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_partner_perks_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
