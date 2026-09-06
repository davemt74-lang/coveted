-- Coveted Admin Agent approved task execution metadata

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE admin_agent_tasks
  ADD COLUMN execution_state ENUM('idle','running','completed','failed','blocked') NOT NULL DEFAULT 'idle' AFTER source_href,
  ADD COLUMN execution_thread_ref VARCHAR(64) NULL AFTER execution_state,
  ADD COLUMN execution_request_id VARCHAR(64) NULL AFTER execution_thread_ref,
  ADD COLUMN execution_provider VARCHAR(32) NULL AFTER execution_request_id,
  ADD COLUMN execution_goal TEXT NULL AFTER execution_provider,
  ADD COLUMN execution_summary TEXT NULL AFTER execution_goal,
  ADD COLUMN execution_error VARCHAR(1000) NULL AFTER execution_summary,
  ADD COLUMN execution_started_at DATETIME NULL AFTER execution_error,
  ADD COLUMN execution_completed_at DATETIME NULL AFTER execution_started_at,
  ADD UNIQUE KEY uq_admin_agent_task_execution_request (owner_user_id, execution_request_id),
  ADD KEY idx_admin_agent_tasks_execution_state (owner_user_id, execution_state, updated_at);
