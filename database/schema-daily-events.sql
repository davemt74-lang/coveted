-- Daily Events / partnered opportunity engine canonical schema fragment.
-- Safe for fresh installs through app/installer.php.

CREATE TABLE IF NOT EXISTS daily_event_opportunities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  event_id BIGINT UNSIGNED NOT NULL UNIQUE,
  business_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  reward_campaign_id BIGINT UNSIGNED NOT NULL,
  attendance_threshold INT UNSIGNED NOT NULL,
  loyalty_points INT UNSIGNED NOT NULL DEFAULT 100,
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  reward_unlocked_at DATETIME NULL,
  attendance_count_at_unlock INT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_daily_event_campaign (reward_campaign_id),
  KEY idx_daily_event_status_event (status,event_id),
  KEY idx_daily_event_business_status (business_id,status,event_id),
  KEY idx_daily_event_location_status (location_id,status,event_id),
  CONSTRAINT fk_daily_event_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_event_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_daily_event_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_daily_event_campaign FOREIGN KEY (reward_campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_daily_event_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_daily_event_threshold CHECK (attendance_threshold > 0),
  CONSTRAINT chk_daily_event_loyalty_points CHECK (loyalty_points <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
