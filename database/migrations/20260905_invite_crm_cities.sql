-- Coveted invite CRM + city network
-- Safe additive migration for existing deployments.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS cities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  region VARCHAR(160) NULL,
  country CHAR(2) NOT NULL DEFAULT 'US',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_city_identity (name,region,country),
  KEY idx_cities_status_sort (status,sort_order,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invite_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  full_name VARCHAR(180) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(80) NULL,
  city_id BIGINT UNSIGNED NULL,
  city_other VARCHAR(180) NULL,
  event_interests_json JSON NOT NULL,
  how_heard VARCHAR(180) NULL,
  message TEXT NULL,
  admin_note TEXT NULL,
  status ENUM('new','contacted','qualified','converted','declined') NOT NULL DEFAULT 'new',
  converted_user_id BIGINT UNSIGNED NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  source_ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_invite_requests_status_created (status,created_at),
  KEY idx_invite_requests_email_created (email,created_at),
  KEY idx_invite_requests_city_status (city_id,status),
  KEY idx_invite_requests_converted_user (converted_user_id),
  CONSTRAINT fk_invite_requests_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
  CONSTRAINT fk_invite_requests_converted_user FOREIGN KEY (converted_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_invite_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_activation_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_activation_user (user_id,used_at,expires_at),
  CONSTRAINT fk_user_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_activation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cities (public_id,name,region,country,timezone,status,sort_order) VALUES
('city_phoenix_az','Phoenix','Arizona','US','America/Phoenix','active',10),
('city_scottsdale_az','Scottsdale','Arizona','US','America/Phoenix','active',20),
('city_tempe_az','Tempe','Arizona','US','America/Phoenix','active',30),
('city_mesa_az','Mesa','Arizona','US','America/Phoenix','active',40),
('city_chandler_az','Chandler','Arizona','US','America/Phoenix','active',50),
('city_gilbert_az','Gilbert','Arizona','US','America/Phoenix','active',60);
