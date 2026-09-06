-- Coveted Admin Agent approved task execution metadata
-- Requires: database/migrations/20260905_admin_agent_tasks.sql
-- Safe to re-import on MySQL/MariaDB installations where the task table exists.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @coveted_schema = DATABASE();

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_state'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_state ENUM(''idle'',''running'',''completed'',''failed'',''blocked'') NOT NULL DEFAULT ''idle'' AFTER source_href'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_thread_ref'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_thread_ref VARCHAR(64) NULL AFTER execution_state'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_request_id'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_request_id VARCHAR(64) NULL AFTER execution_thread_ref'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_provider'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_provider VARCHAR(32) NULL AFTER execution_request_id'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_goal'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_goal TEXT NULL AFTER execution_provider'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_summary'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_summary TEXT NULL AFTER execution_goal'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_error'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_error VARCHAR(1000) NULL AFTER execution_summary'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_started_at'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_started_at DATETIME NULL AFTER execution_error'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND COLUMN_NAME='execution_completed_at'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD COLUMN execution_completed_at DATETIME NULL AFTER execution_started_at'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND INDEX_NAME='uq_admin_agent_task_execution_request'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD UNIQUE KEY uq_admin_agent_task_execution_request (owner_user_id, execution_request_id)'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@coveted_schema AND TABLE_NAME='admin_agent_tasks' AND INDEX_NAME='idx_admin_agent_tasks_execution_state'),
  'SELECT 1',
  'ALTER TABLE admin_agent_tasks ADD KEY idx_admin_agent_tasks_execution_state (owner_user_id, execution_state, updated_at)'
);
PREPARE coveted_stmt FROM @sql; EXECUTE coveted_stmt; DEALLOCATE PREPARE coveted_stmt;
