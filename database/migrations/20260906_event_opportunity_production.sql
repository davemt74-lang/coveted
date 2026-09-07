-- Coveted Event Opportunity Engine + Event Production Workspace
-- Opportunity recommendations remain deterministic/read-only. This migration
-- stores only event-production execution state and notes.

CREATE TABLE IF NOT EXISTS event_production_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(64) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    phase ENUM('planning','pre_event','arrival','live','closeout') NOT NULL DEFAULT 'planning',
    item_type ENUM('task','checklist','timing','staffing','venue','artist','guest','benefit','safety','other') NOT NULL DEFAULT 'task',
    title VARCHAR(255) NOT NULL,
    detail TEXT NULL,
    priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    status ENUM('open','in_progress','blocked','completed','cancelled') NOT NULL DEFAULT 'open',
    assigned_user_id BIGINT UNSIGNED NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_production_items_public (public_id),
    KEY idx_event_production_items_event (event_id, status, phase, sort_order),
    KEY idx_event_production_items_assignee (assigned_user_id, status, due_at),
    CONSTRAINT fk_event_production_items_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_production_items_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_production_items_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_production_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(64) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    note_type ENUM('general','run_of_show','venue','artist','guest','incident','closeout') NOT NULL DEFAULT 'general',
    body TEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_production_notes_public (public_id),
    KEY idx_event_production_notes_event (event_id, created_at),
    CONSTRAINT fk_event_production_notes_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_production_notes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
