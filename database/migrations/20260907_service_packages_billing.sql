-- Coveted service packages, entitlements, provider-neutral subscriptions and Admin billing bypass.
-- MySQL 8+. Run once on an existing Coveted installation. No runtime DDL is used.

CREATE TABLE IF NOT EXISTS service_packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_key VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  monthly_price_cents INT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  sort_order INT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_service_packages_active_sort (is_active,sort_order,id),
  KEY idx_service_packages_default_active (is_default,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_package_entitlements (
  package_id BIGINT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(100) NOT NULL,
  entitlement_value VARCHAR(255) NOT NULL DEFAULT '1',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (package_id,entitlement_key),
  KEY idx_service_entitlements_key_enabled (entitlement_key,enabled),
  CONSTRAINT fk_service_entitlements_package FOREIGN KEY (package_id) REFERENCES service_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  subject_type ENUM('user','business') NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  provider_customer_ref VARCHAR(255) NULL,
  provider_subscription_ref VARCHAR(255) NULL,
  status ENUM('trialing','active','past_due','paused','cancelled','expired') NOT NULL DEFAULT 'active',
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  cancelled_at DATETIME NULL,
  provider_metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_provider_subscription (provider,provider_subscription_ref),
  KEY idx_billing_user_status_period (user_id,status,current_period_end),
  KEY idx_billing_business_status_period (business_id,status,current_period_end),
  KEY idx_billing_package_status (package_id,status),
  CONSTRAINT fk_billing_subscription_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_subscription_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_subscription_package FOREIGN KEY (package_id) REFERENCES service_packages(id) ON DELETE RESTRICT,
  CONSTRAINT chk_billing_subject_scope CHECK (
    (subject_type = 'user' AND user_id IS NOT NULL AND business_id IS NULL)
    OR (subject_type = 'business' AND business_id IS NOT NULL AND user_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_package_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  scope_type ENUM('user','business','user_role') NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  business_id BIGINT UNSIGNED NULL,
  role_key ENUM('attendee','attendee_host','artist_partner','system_admin') NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  reason VARCHAR(1000) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_service_assignment_user_active (user_id,revoked_at,starts_at,ends_at),
  KEY idx_service_assignment_business_active (business_id,revoked_at,starts_at,ends_at),
  KEY idx_service_assignment_role_active (role_key,revoked_at,starts_at,ends_at),
  KEY idx_service_assignment_package (package_id,created_at),
  CONSTRAINT fk_service_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_assignment_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_assignment_package FOREIGN KEY (package_id) REFERENCES service_packages(id) ON DELETE RESTRICT,
  CONSTRAINT fk_service_assignment_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_service_assignment_revoker FOREIGN KEY (revoked_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_service_assignment_scope CHECK (
    (scope_type = 'user' AND user_id IS NOT NULL AND business_id IS NULL AND role_key IS NULL)
    OR (scope_type = 'business' AND business_id IS NOT NULL AND user_id IS NULL AND role_key IS NULL)
    OR (scope_type = 'user_role' AND role_key IS NOT NULL AND user_id IS NULL AND business_id IS NULL)
  ),
  CONSTRAINT chk_service_assignment_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a safe catalog without making pricing decisions on behalf of the operator.
-- System Admin can set monthly prices in Service Packages before enabling checkout.
INSERT IGNORE INTO service_packages
  (package_key,name,description,monthly_price_cents,currency,sort_order,is_active,is_default)
VALUES
  ('free','Free','Core Coveted access. Existing product access is not removed until a feature explicitly adopts an entitlement gate.',0,'USD',10,1,1),
  ('plus','Plus','Expanded member service package. Set the live monthly price in System Admin.',NULL,'USD',20,1,0),
  ('partner','Partner','Business and location partner service package. Set the live monthly price in System Admin.',NULL,'USD',30,1,0);

INSERT IGNORE INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.core','1',1 FROM service_packages WHERE package_key='free';
INSERT IGNORE INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.core','1',1 FROM service_packages WHERE package_key='plus';
INSERT IGNORE INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.plus','1',1 FROM service_packages WHERE package_key='plus';
INSERT IGNORE INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'membership.core','1',1 FROM service_packages WHERE package_key='partner';
INSERT IGNORE INTO service_package_entitlements (package_id,entitlement_key,entitlement_value,enabled)
SELECT id,'partner.workspace','1',1 FROM service_packages WHERE package_key='partner';
