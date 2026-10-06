ALTER TABLE tasks ADD COLUMN archived_at DATETIME NULL AFTER is_private;
ALTER TABLE tasks ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at;
ALTER TABLE tasks ADD INDEX idx_tasks_archived (archived_at,status,due_date);
