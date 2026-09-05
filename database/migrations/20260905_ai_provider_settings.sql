-- Coveted AI provider credential metadata
-- API keys are encrypted in application code before being written here.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS ai_provider_settings (
  provider VARCHAR(32) PRIMARY KEY,
  secret_ciphertext TEXT NULL,
  secret_last4 VARCHAR(8) NULL,
  model VARCHAR(190) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ai_provider_enabled (enabled),
  CONSTRAINT fk_ai_provider_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_provider_settings (provider, model, enabled) VALUES
  ('openai', 'gpt-5.6', 0),
  ('anthropic', 'claude-sonnet-5', 0),
  ('elevenlabs', NULL, 0);
