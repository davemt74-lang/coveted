-- Coveted Event Proposals + Playbooks
-- System Admin planning layer between deterministic opportunities and canonical Events.

CREATE TABLE IF NOT EXISTS event_playbooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  playbook_key VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  event_type ENUM('regular','mystery','private_table','member_plus_one','session') NOT NULL DEFAULT 'regular',
  audience ENUM('group','invitation_only') NOT NULL DEFAULT 'group',
  default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  default_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  plus_one_allowed TINYINT(1) NOT NULL DEFAULT 0,
  location_visibility ENUM('immediate','scheduled_reveal','host_only') NOT NULL DEFAULT 'immediate',
  required_host_roles_json JSON NULL,
  recommended_benefits_json JSON NULL,
  guest_cadence_json JSON NULL,
  run_of_show_json JSON NULL,
  production_items_json JSON NULL,
  closeout_json JSON NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_event_playbooks_status_name (status,name),
  CONSTRAINT fk_event_playbooks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_event_playbook_duration CHECK (default_duration_minutes BETWEEN 30 AND 720),
  CONSTRAINT chk_event_playbook_capacity CHECK (default_capacity BETWEEN 1 AND 1000),
  CONSTRAINT chk_event_playbook_plus_one CHECK (plus_one_allowed IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  opportunity_key VARCHAR(220) NULL,
  playbook_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  concept TEXT NULL,
  status ENUM('idea','reviewing','partner_contacted','negotiating','approved','converted','declined') NOT NULL DEFAULT 'idea',
  proposed_start_at DATETIME NULL,
  alternate_start_at DATETIME NULL,
  expected_attendance SMALLINT UNSIGNED NULL,
  capacity SMALLINT UNSIGNED NOT NULL,
  partner_contact_ref VARCHAR(64) NULL,
  partner_contribution TEXT NULL,
  proposed_perk TEXT NULL,
  special_requirements TEXT NULL,
  internal_notes TEXT NULL,
  approved_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  converted_event_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_event_proposals_status_start (status,proposed_start_at),
  KEY idx_event_proposals_relationship (business_id,group_id,location_id,status),
  KEY idx_event_proposals_playbook (playbook_id,status),
  KEY idx_event_proposals_opportunity (opportunity_key),
  CONSTRAINT fk_event_proposals_playbook FOREIGN KEY (playbook_id) REFERENCES event_playbooks(id) ON DELETE RESTRICT,
  CONSTRAINT fk_event_proposals_group FOREIGN KEY (group_id) REFERENCES social_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_proposals_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_proposals_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_proposals_artist FOREIGN KEY (artist_id) REFERENCES artist_profiles(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_proposals_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_proposals_event FOREIGN KEY (converted_event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_proposals_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_event_proposal_attendance CHECK (expected_attendance IS NULL OR expected_attendance > 0),
  CONSTRAINT chk_event_proposal_capacity CHECK (capacity > 0),
  CONSTRAINT chk_event_proposal_time_order CHECK (alternate_start_at IS NULL OR proposed_start_at IS NULL OR alternate_start_at <> proposed_start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_proposal_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  proposal_id BIGINT UNSIGNED NOT NULL,
  update_type ENUM('internal_note','partner_contact','partner_feedback','status_change','approval','conversion') NOT NULL,
  contact_ref VARCHAR(64) NULL,
  status_from VARCHAR(40) NULL,
  status_to VARCHAR(40) NULL,
  body TEXT NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event_proposal_updates_proposal_time (proposal_id,created_at,id),
  CONSTRAINT fk_event_proposal_updates_proposal FOREIGN KEY (proposal_id) REFERENCES event_proposals(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_proposal_updates_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO event_playbooks
  (public_id,playbook_key,name,description,event_type,audience,default_duration_minutes,default_capacity,plus_one_allowed,location_visibility,required_host_roles_json,recommended_benefits_json,guest_cadence_json,run_of_show_json,production_items_json,closeout_json,status)
VALUES
('playbook-supper-club','supper_club','Supper Club','Small-table or long-table dinner focused on introductions and repeat connection.','private_table','invitation_only',150,20,0,'immediate',JSON_ARRAY('lead','checkin'),JSON_ARRAY('partner perk','return visit value'),JSON_OBJECT('invite_days_before',14,'reminder_days_before',2),JSON_ARRAY('Host welcome','First introductions','Shared meal','Closing reconnect prompt'),JSON_ARRAY('Confirm menu/package','Confirm seating plan','Prepare introductions','Confirm partner perk'),JSON_ARRAY('Partner debrief','Attendance closeout','Return-value follow-up'),'active'),
('playbook-mystery','mystery_event','Mystery Event','A staged-reveal experience where location or experience details are intentionally withheld.','mystery','group',180,20,0,'scheduled_reveal',JSON_ARRAY('lead','cohost','checkin'),JSON_ARRAY('surprise reward','return visit value'),JSON_OBJECT('invite_days_before',18,'reminder_days_before',3),JSON_ARRAY('Arrival checkpoint','Final reveal','Hosted experience','Surprise close'),JSON_ARRAY('Set reveal schedule','Confirm private arrival instructions','Prepare contingency reveal','Confirm surprise value'),JSON_ARRAY('Reveal-performance review','Partner debrief','Incident review'),'active'),
('playbook-listening','listening_session','Listening Session','Artist-led or record-led intimate music gathering with conversation between sets.','session','group',180,30,1,'immediate',JSON_ARRAY('lead','cohost','checkin'),JSON_ARRAY('artist media reward','partner perk'),JSON_OBJECT('invite_days_before',14,'reminder_days_before',2),JSON_ARRAY('Doors','Host welcome','Listening set','Intermission','Second set','Close'),JSON_ARRAY('Confirm artist/load-in','Confirm audio setup','Soundcheck','Prepare artist reward'),JSON_ARRAY('Artist debrief','Media/reward closeout','Attendance closeout'),'active'),
('playbook-artist','artist_appearance','Artist Appearance','Featured artist experience integrated with a Coveted social gathering.','regular','group',180,36,1,'immediate',JSON_ARRAY('lead','cohost','checkin'),JSON_ARRAY('artist reward','partner return value'),JSON_OBJECT('invite_days_before',21,'reminder_days_before',3),JSON_ARRAY('Doors','Host welcome','Artist appearance','Member interaction','Close'),JSON_ARRAY('Confirm artist terms','Confirm arrival/load-in','Confirm performance window','Prepare artist reward'),JSON_ARRAY('Artist debrief','Partner debrief','Reward performance review'),'active'),
('playbook-cocktail','cocktail_social','Cocktail / Social','Low-friction social gathering built around introductions, drinks and light programming.','regular','group',150,30,1,'immediate',JSON_ARRAY('lead','cohost','checkin'),JSON_ARRAY('partner perk','return visit offer'),JSON_OBJECT('invite_days_before',10,'reminder_days_before',2),JSON_ARRAY('Arrival','Introductions','Open social','Hosted connection moment','Close'),JSON_ARRAY('Confirm reserved area','Confirm beverage package','Prepare introductions','Confirm return offer'),JSON_ARRAY('Partner debrief','Reconnect follow-up','Claim review'),'active'),
('playbook-wellness','wellness_session','Wellness Session','Small-format guided wellness or recovery experience.','session','invitation_only',120,18,0,'immediate',JSON_ARRAY('lead','checkin'),JSON_ARRAY('service perk','return visit offer'),JSON_OBJECT('invite_days_before',14,'reminder_days_before',2),JSON_ARRAY('Arrival/check-in','Orientation','Guided session','Recovery/social time','Close'),JSON_ARRAY('Confirm waivers/requirements','Confirm equipment','Prepare accessibility notes','Confirm service perk'),JSON_ARRAY('Safety review','Partner debrief','Follow-up value'),'active'),
('playbook-private-table','private_table','Private Table','Small invitation-only table designed for deeper conversation and introductions.','private_table','invitation_only',135,12,0,'immediate',JSON_ARRAY('lead','checkin'),JSON_ARRAY('partner perk'),JSON_OBJECT('invite_days_before',10,'reminder_days_before',2),JSON_ARRAY('Arrival','Host introductions','Meal/experience','Closing connections'),JSON_ARRAY('Confirm table','Confirm seating','Prepare guest context','Confirm perk'),JSON_ARRAY('Partner debrief','Reconnect prompts'),'active'),
('playbook-discovery','local_discovery','Local Discovery','Neighborhood or independent-business discovery experience with a guided social component.','regular','group',180,24,1,'immediate',JSON_ARRAY('lead','cohost','checkin'),JSON_ARRAY('partner perk','discovery reward'),JSON_OBJECT('invite_days_before',14,'reminder_days_before',2),JSON_ARRAY('Meet point','Discovery segment','Partner experience','Social close'),JSON_ARRAY('Confirm route/meeting point','Confirm partner handoff','Prepare contingency plan','Confirm reward'),JSON_ARRAY('Partner debrief','Discovery notes','Return-value review'),'active')
ON DUPLICATE KEY UPDATE
  name=VALUES(name),description=VALUES(description),event_type=VALUES(event_type),audience=VALUES(audience),
  default_duration_minutes=VALUES(default_duration_minutes),default_capacity=VALUES(default_capacity),plus_one_allowed=VALUES(plus_one_allowed),
  location_visibility=VALUES(location_visibility),required_host_roles_json=VALUES(required_host_roles_json),recommended_benefits_json=VALUES(recommended_benefits_json),
  guest_cadence_json=VALUES(guest_cadence_json),run_of_show_json=VALUES(run_of_show_json),production_items_json=VALUES(production_items_json),closeout_json=VALUES(closeout_json);
