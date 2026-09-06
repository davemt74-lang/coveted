-- Coveted Admin Agent persistent task queue

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS admin_agent_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  detail TEXT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
  status ENUM('suggested','approved','in_progress','completed','dismissed') NOT NULL DEFAULT 'suggested',
  source_type ENUM('opportunity','manual') NOT NULL DEFAULT 'manual',
  source_key VARCHAR(120) NULL,
  source_href VARCHAR(700) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_agent_task_source (owner_user_id,source_type,source_key),
  KEY idx_admin_agent_tasks_owner_status_priority (owner_user_id,status,priority,updated_at),
  KEY idx_admin_agent_tasks_updated (updated_at),
  CONSTRAINT fk_admin_agent_tasks_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_agent_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_admin_agent_tasks_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_admin_agent_task_priority CHECK (priority BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
