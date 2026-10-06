USE gerenciador_tarefas;

ALTER TABLE tasks ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER due_date;
ALTER TABLE simple_tasks ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 1 AFTER due_date;
