<?php
// Extensão aditiva: não altera linhas nem colunas das tabelas existentes.
function ensure_objectives_schema(): void {
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS subtask_objectives (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        subtask_id INT UNSIGNED NOT NULL,
        title VARCHAR(220) NOT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_objectives_subtask (subtask_id),
        CONSTRAINT fk_objectives_subtask FOREIGN KEY (subtask_id)
        REFERENCES subtasks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS subtask_details (
        subtask_id INT UNSIGNED PRIMARY KEY,
        description TEXT NOT NULL,
        CONSTRAINT fk_subtask_details_subtask FOREIGN KEY (subtask_id)
        REFERENCES subtasks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}
function objective_subtasks(int $taskId): array {
    $q=db()->prepare('SELECT s.*, COUNT(o.id) objective_total, COALESCE(SUM(o.completed),0) objective_done
        FROM subtasks s LEFT JOIN subtask_objectives o ON o.subtask_id=s.id
        WHERE s.task_id=? GROUP BY s.id ORDER BY s.id');
    $q->execute([$taskId]);
    $rows=$q->fetchAll();
    $d=db()->prepare('SELECT d.subtask_id,d.description FROM subtask_details d JOIN subtasks s ON s.id=d.subtask_id WHERE s.task_id=?');
    $d->execute([$taskId]);
    $descriptions=$d->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as &$row) $row['description']=$descriptions[$row['id']] ?? '';
    unset($row);
    return $rows;
}
function objective_ratio(array $s): float {
    return (int)$s['objective_total'] ? (int)$s['objective_done']/(int)$s['objective_total'] : (int)$s['completed'];
}
function objective_task_progress(array $subtasks): int {
    if (!$subtasks) return 0;
    return (int)round(array_sum(array_map('objective_ratio', $subtasks))/count($subtasks)*100);
}
function objective_ring(int $percent, string $label='Progresso'): string {
    $p=max(0,min(100,$percent));
    return '<svg class="objective-ring" viewBox="0 0 120 120" role="img" aria-label="'.e($label).' '.$p.'%"><circle cx="60" cy="60" r="48" fill="none" stroke="#e2e8f0" stroke-width="12"/><circle cx="60" cy="60" r="48" fill="none" stroke="#2563eb" stroke-width="12" pathLength="100" stroke-dasharray="'.$p.' 100" transform="rotate(-90 60 60)"/><text x="60" y="66" text-anchor="middle" fill="currentColor" font-size="22" font-family="Arial" font-weight="bold">'.$p.'%</text></svg>';
}

function save_subtask_description(int $subtaskId, string $description): void {
    $q=db()->prepare('INSERT INTO subtask_details(subtask_id,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)');
    $q->execute([$subtaskId,$description]);
}

function short_subtask_title(string $title): string {
    return trim(preg_replace('/\s+/u',' ',$title) ?? $title);
}
// Chamado com a linha da tarefa principal bloqueada na mesma transação.
function subtask_title_exists(int $taskId, string $title, int $exceptId=0): bool {
    $q=db()->prepare('SELECT title FROM subtasks WHERE task_id=? AND id<>?');
    $q->execute([$taskId,$exceptId]);
    $normalized=mb_strtolower(short_subtask_title($title),'UTF-8');
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        if (mb_strtolower(short_subtask_title($existing),'UTF-8') === $normalized) return true;
    }
    return false;
}
