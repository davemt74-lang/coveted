-- Replace the original Phoenix-metro launch seed with a nationwide city list.
-- Legacy seed rows are archived, not deleted, so historical references remain valid.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

UPDATE cities
SET status = 'archived', updated_at = UTC_TIMESTAMP()
WHERE public_id IN (
  'city_scottsdale_az',
  'city_tempe_az',
  'city_mesa_az',
  'city_chandler_az',
  'city_gilbert_az'
);

INSERT INTO cities (public_id,name,region,country,timezone,status,sort_order) VALUES
('city_san_francisco_ca','San Francisco','California','US','America/Los_Angeles','active',10),
('city_san_diego_ca','San Diego','California','US','America/Los_Angeles','active',20),
('city_phoenix_az','Phoenix','Arizona','US','America/Phoenix','active',30),
('city_minneapolis_mn','Minneapolis','Minnesota','US','America/Chicago','active',40),
('city_new_york_ny','New York','New York','US','America/New_York','active',50),
('city_austin_tx','Austin','Texas','US','America/Chicago','active',60),
('city_chicago_il','Chicago','Illinois','US','America/Chicago','active',70),
('city_miami_fl','Miami','Florida','US','America/New_York','active',80),
('city_nashville_tn','Nashville','Tennessee','US','America/Chicago','active',90),
('city_denver_co','Denver','Colorado','US','America/Denver','active',100),
('city_seattle_wa','Seattle','Washington','US','America/Los_Angeles','active',110),
('city_atlanta_ga','Atlanta','Georgia','US','America/New_York','active',120)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  region = VALUES(region),
  country = VALUES(country),
  timezone = VALUES(timezone),
  status = 'active',
  sort_order = VALUES(sort_order),
  updated_at = UTC_TIMESTAMP();
