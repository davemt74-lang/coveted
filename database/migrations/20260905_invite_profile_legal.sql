-- Coveted invite profile metadata
-- Additive migration for deployed installations.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS invite_request_profiles (
  invite_request_id BIGINT UNSIGNED PRIMARY KEY,
  goals_json JSON NOT NULL,
  source_keys_json JSON NOT NULL,
  gender_key VARCHAR(40) NULL,
  gender_self_description VARCHAR(120) NULL,
  social_links_json JSON NULL,
  additional_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_invite_request_profiles_request FOREIGN KEY (invite_request_id) REFERENCES invite_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profile_intake (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  invite_request_id BIGINT UNSIGNED NULL,
  goals_json JSON NOT NULL,
  source_keys_json JSON NOT NULL,
  gender_key VARCHAR(40) NULL,
  gender_self_description VARCHAR(120) NULL,
  social_links_json JSON NULL,
  additional_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_profile_intake_request (invite_request_id),
  CONSTRAINT fk_user_profile_intake_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_profile_intake_request FOREIGN KEY (invite_request_id) REFERENCES invite_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
