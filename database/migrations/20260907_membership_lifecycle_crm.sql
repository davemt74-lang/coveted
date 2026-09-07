-- Coveted Membership Lifecycle CRM
-- First-class private membership lifecycle state and append-only transition history.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS membership_lifecycle (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  lifecycle_state ENUM('invited','applicant','active','engaged','drifting','paused','alumni') NOT NULL DEFAULT 'active',
  state_since DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  renewal_due_at DATETIME NULL,
  paused_until DATETIME NULL,
  reason VARCHAR(1000) NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_membership_lifecycle_state_since (lifecycle_state,state_since),
  KEY idx_membership_lifecycle_renewal (renewal_due_at,lifecycle_state),
  CONSTRAINT fk_membership_lifecycle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_membership_lifecycle_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_lifecycle_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  lifecycle_id BIGINT UNSIGNED NOT NULL,
  from_state ENUM('invited','applicant','active','engaged','drifting','paused','alumni') NULL,
  to_state ENUM('invited','applicant','active','engaged','drifting','paused','alumni') NOT NULL,
  source ENUM('baseline','system_admin','admin_agent_approved') NOT NULL DEFAULT 'system_admin',
  reason VARCHAR(1000) NULL,
  evidence_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_membership_lifecycle_history_lifecycle (lifecycle_id,created_at,id),
  KEY idx_membership_lifecycle_history_state (to_state,created_at),
  CONSTRAINT fk_membership_lifecycle_history_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES membership_lifecycle(id) ON DELETE CASCADE,
  CONSTRAINT fk_membership_lifecycle_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
