-- Coveted persistent Admin Agent conversations
-- Server-owned System Admin thread/message history.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS admin_agent_threads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT 'New Chat',
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  last_message_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_admin_agent_threads_owner_status (owner_user_id,status,last_message_at,id),
  CONSTRAINT fk_admin_agent_threads_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_agent_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id BIGINT UNSIGNED NOT NULL,
  request_id VARCHAR(64) NULL,
  role ENUM('user','assistant','action') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  provider VARCHAR(32) NULL,
  model VARCHAR(190) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_agent_messages_thread_id (thread_id,id),
  KEY idx_admin_agent_messages_request (thread_id,request_id,id),
  CONSTRAINT fk_admin_agent_messages_thread FOREIGN KEY (thread_id) REFERENCES admin_agent_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
