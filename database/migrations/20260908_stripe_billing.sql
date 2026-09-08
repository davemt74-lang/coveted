-- Coveted Stripe billing adapter support.
-- MySQL 8+. Run once after 20260907_service_packages_billing.sql.

CREATE TABLE IF NOT EXISTS billing_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  subject_type ENUM('user','business') NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  provider VARCHAR(40) NOT NULL,
  provider_customer_ref VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_customer_provider_ref (provider,provider_customer_ref),
  UNIQUE KEY uq_billing_customer_user_provider (provider,user_id),
  UNIQUE KEY uq_billing_customer_business_provider (provider,business_id),
  CONSTRAINT fk_billing_customer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_customer_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT chk_billing_customer_scope CHECK (
    (subject_type = 'user' AND user_id IS NOT NULL AND business_id IS NULL)
    OR (subject_type = 'business' AND business_id IS NOT NULL AND user_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_checkout_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  provider VARCHAR(40) NOT NULL,
  provider_session_ref VARCHAR(255) NULL,
  subject_type ENUM('user','business') NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  status ENUM('creating','open','completed','expired','failed') NOT NULL DEFAULT 'creating',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_checkout_provider_ref (provider,provider_session_ref),
  KEY idx_billing_checkout_user_status (user_id,status,created_at),
  KEY idx_billing_checkout_business_status (business_id,status,created_at),
  KEY idx_billing_checkout_package_status (package_id,status,created_at),
  CONSTRAINT fk_billing_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_checkout_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_checkout_package FOREIGN KEY (package_id) REFERENCES service_packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_billing_checkout_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_billing_checkout_scope CHECK (
    (subject_type = 'user' AND user_id IS NOT NULL AND business_id IS NULL)
    OR (subject_type = 'business' AND business_id IS NOT NULL AND user_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL,
  event_ref VARCHAR(255) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  payload_sha256 CHAR(64) NOT NULL,
  status ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_webhook_provider_event (provider,event_ref),
  KEY idx_billing_webhook_status_updated (status,updated_at),
  KEY idx_billing_webhook_type_received (event_type,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
