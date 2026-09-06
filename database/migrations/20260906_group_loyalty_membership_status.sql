-- Group Loyalty + Membership Status
-- Private append-only points ledger + durable group milestones.
-- Apply to existing Coveted databases before enabling the Loyalty worker/UI.

CREATE TABLE IF NOT EXISTS loyalty_point_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  source_type VARCHAR(64) NOT NULL,
  source_ref VARCHAR(128) NOT NULL,
  global_points INT NOT NULL DEFAULT 0,
  group_points INT NOT NULL DEFAULT 0,
  description VARCHAR(255) NOT NULL,
  occurred_at DATETIME NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyalty_source (user_id,source_type,source_ref),
  KEY idx_loyalty_user_created (user_id,created_at),
  KEY idx_loyalty_group_user (group_id,user_id,created_at),
  KEY idx_loyalty_event (event_id,user_id),
  KEY idx_loyalty_source_type (source_type,occurred_at),
  CONSTRAINT fk_loyalty_point_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_loyalty_point_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_loyalty_point_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT chk_loyalty_point_nonzero CHECK (global_points <> 0 OR group_points <> 0),
  CONSTRAINT chk_loyalty_point_range CHECK (global_points BETWEEN -1000000 AND 1000000 AND group_points BETWEEN -1000000 AND 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  milestone_key VARCHAR(64) NOT NULL,
  milestone_value INT UNSIGNED NOT NULL DEFAULT 1,
  source_type VARCHAR(64) NULL,
  source_ref VARCHAR(128) NULL,
  achieved_at DATETIME NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyalty_milestone (user_id,group_id,milestone_key),
  KEY idx_loyalty_milestones_group_key (group_id,milestone_key,achieved_at),
  KEY idx_loyalty_milestones_user (user_id,achieved_at),
  CONSTRAINT fk_loyalty_milestone_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_loyalty_milestone_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
