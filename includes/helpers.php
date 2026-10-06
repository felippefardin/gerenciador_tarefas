<?php
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function flash_message(string $message, string $type = 'success'): void {
    $allowed = ['success', 'error', 'warning', 'info'];
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => in_array($type, $allowed, true) ? $type : 'info',
    ];
}
function take_flash(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    if (is_array($flash)) {
        return [
            'message' => (string)($flash['message'] ?? ''),
            'type' => in_array(($flash['type'] ?? ''), ['success','error','warning','info'], true) ? $flash['type'] : 'info',
        ];
    }
    // Compatibilidade com mensagens antigas ainda gravadas na sessão.
    return ['message' => (string)$flash, 'type' => 'success'];
}
function task_status_label(string $status): string {
    return match ($status) {
        'todo' => 'A Fazer',
        'doing' => 'Em andamento',
        'review' => 'Em revisão',
        'done' => 'Concluído',
        default => $status,
    };
}
function priority_label(string $priority): string {
    return match ($priority) {
        'low' => 'Baixa',
        'medium' => 'Média',
        'high' => 'Alta',
        'urgent' => 'Urgente',
        default => $priority,
    };
}

function reminder_deadline_class(?string $dueDate): string {
    if (!$dueDate) return '';
    $today = new DateTimeImmutable('today');
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    if (!$due) return '';
    $days = (int)$today->diff($due)->format('%r%a');
    // Vermelho: vencido ou vence hoje; amarelo: próximos 3 dias; verde: mais de 3 dias.
    if ($days <= 0) return 'deadline-red';
    if ($days <= 3) return 'deadline-yellow';
    return 'deadline-green';
}

function ensure_v8_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v4_schema();
    if (!db_column_exists('tasks', 'is_private')) {
        db()->exec('ALTER TABLE tasks ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER due_date');
    }
    if (!db_column_exists('simple_tasks', 'is_private')) {
        db()->exec('ALTER TABLE simple_tasks ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 1 AFTER due_date');
    }
    $done = true;
}

function ensure_v14_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v8_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS task_assignees (
      task_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (task_id, user_id),
      INDEX idx_task_assignees_user (user_id, task_id),
      CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
      CONSTRAINT fk_task_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    db()->exec('INSERT IGNORE INTO task_assignees(task_id,user_id) SELECT id,assignee_id FROM tasks WHERE assignee_id IS NOT NULL');
    $done = true;
}

function ensure_v17_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v14_schema();
    db()->exec("UPDATE users SET role='user' WHERE role='manager'");
    $roleType = (string)db()->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='role' LIMIT 1")->fetchColumn();
    if (str_contains($roleType, "'manager'")) {
        db()->exec("ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user'");
    }
    $master = db()->prepare("UPDATE users SET role='admin' WHERE username=?");
    $master->execute(['felippe.andreata']);
    db()->exec("CREATE TABLE IF NOT EXISTS reminder_assignees (
      reminder_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (reminder_id,user_id),
      INDEX idx_reminder_assignees_user (user_id,reminder_id),
      CONSTRAINT fk_reminder_assignees_reminder FOREIGN KEY (reminder_id) REFERENCES simple_tasks(id) ON DELETE CASCADE,
      CONSTRAINT fk_reminder_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $done = true;
}

function ensure_v22_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v17_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      recipient_id INT UNSIGNED NOT NULL,
      actor_id INT UNSIGNED NULL,
      action VARCHAR(100) NOT NULL,
      entity_type VARCHAR(60) NOT NULL,
      entity_id INT UNSIGNED NULL,
      details TEXT NULL,
      link VARCHAR(255) NULL,
      read_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_notifications_recipient (recipient_id,read_at,created_at),
      CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $done = true;
}

function ensure_v23_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v22_schema();
    if (!db_column_exists('tasks', 'archived_at')) {
        db()->exec('ALTER TABLE tasks ADD COLUMN archived_at DATETIME NULL AFTER is_private');
    }
    if (!db_column_exists('tasks', 'archived_by')) {
        db()->exec('ALTER TABLE tasks ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at');
    }
    if (!db_index_exists('tasks', 'idx_tasks_archived')) {
        db()->exec('ALTER TABLE tasks ADD INDEX idx_tasks_archived (archived_at,status,due_date)');
    }
    $done = true;
}

function can_manage_task(array $task, int $userId): bool {
    if ((int)$task['creator_id'] === $userId) return true;
    if (!empty($task['is_private'])) return false;
    $stmt = db()->prepare('SELECT 1 FROM task_assignees WHERE task_id=? AND user_id=? LIMIT 1');
    $stmt->execute([(int)$task['id'], $userId]);
    return (bool)$stmt->fetchColumn();
}

function can_manage_reminder(array $reminder, int $userId): bool {
    return (int)$reminder['user_id'] === $userId;
}

function reminder_assignee_names_sql(string $alias = 's'): string {
    return "(SELECT GROUP_CONCAT(ru.name ORDER BY ru.name SEPARATOR ', ') FROM reminder_assignees ra JOIN users ru ON ru.id=ra.user_id WHERE ra.reminder_id={$alias}.id)";
}

function task_assignee_names_sql(string $taskAlias = 't'): string {
    return "(SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ') FROM task_assignees ta2 JOIN users u2 ON u2.id=ta2.user_id WHERE ta2.task_id={$taskAlias}.id)";
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida. Volte à página anterior e tente novamente.');
    }
}

function is_image_name(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true);
}

function remove_uploaded_file(?string $storedName): void {
    if (!$storedName) return;
    $path = __DIR__ . '/../uploads/' . basename($storedName);
    if (is_file($path)) @unlink($path);
}

function normalize_username(string $username): string {
    return strtolower(trim($username));
}

function valid_username(string $username): bool {
    return (bool)preg_match('/^[a-z0-9._-]{3,80}$/', $username);
}

function ensure_v2_schema(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS comment_images (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      comment_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      stored_name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_comment_images_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
      CONSTRAINT fk_comment_images_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $done = true;
}

function ensure_v4_schema(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS simple_tasks (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      title VARCHAR(255) NOT NULL,
      due_date DATE NULL,
      completed TINYINT(1) NOT NULL DEFAULT 0,
      completed_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_simple_tasks_user_completed (user_id, completed),
      INDEX idx_simple_tasks_due_date (due_date),
      CONSTRAINT fk_simple_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $done = true;
}

function db_table_exists(string $table): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function db_column_exists(string $table, string $column): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function db_index_exists(string $table, string $index): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function db_column_nullable(string $table, string $column): bool {
    $stmt = db()->prepare('SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return strtoupper((string)$stmt->fetchColumn()) === 'YES';
}

function username_base_from_email(string $email, int $id): string {
    $prefix = strtolower((string)strstr($email, '@', true));
    $base = preg_replace('/[^a-z0-9._-]+/', '', $prefix) ?: ('usuario' . $id);
    $base = trim($base, '._-');
    if (strlen($base) < 3) $base = 'usuario' . $id;
    return substr($base, 0, 60);
}

function ensure_v6_schema(): void {
    static $done = false;
    if ($done) return;

    if (!db_table_exists('users')) {
        $done = true;
        return;
    }

    if (!db_column_exists('users', 'username')) {
        db()->exec('ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL AFTER name');
    }

    $users = db()->query('SELECT id, email, username FROM users ORDER BY id')->fetchAll();
    foreach ($users as $usr) {
        if (!empty($usr['username'])) continue;
        $base = username_base_from_email((string)$usr['email'], (int)$usr['id']);
        $candidate = $base;
        $suffix = 2;
        while (true) {
            $check = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $check->execute([$candidate, $usr['id']]);
            if (!$check->fetch()) break;
            $candidate = substr($base, 0, 70) . $suffix;
            $suffix++;
        }
        $up = db()->prepare('UPDATE users SET username = ? WHERE id = ?');
        $up->execute([$candidate, $usr['id']]);
    }

    if (db_column_nullable('users', 'username')) {
        db()->exec('ALTER TABLE users MODIFY username VARCHAR(80) NOT NULL');
    }
    if (!db_index_exists('users', 'uniq_users_username')) {
        db()->exec('ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)');
    }

    db()->exec("CREATE TABLE IF NOT EXISTS password_reset_codes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      purpose VARCHAR(30) NOT NULL DEFAULT 'forgot',
      code_hash VARCHAR(255) NOT NULL,
      expires_at DATETIME NOT NULL,
      attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
      used_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_reset_user_purpose (user_id, purpose, used_at),
      INDEX idx_reset_expires (expires_at),
      CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    db()->exec("DELETE FROM password_reset_codes
        WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
           OR expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $done = true;
}

function ensure_v7_schema(): void {
    static $done = false;
    if ($done) return;
    ensure_v6_schema();

    db()->exec("CREATE TABLE IF NOT EXISTS email_change_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      new_email VARCHAR(180) NOT NULL,
      current_code_hash VARCHAR(255) NOT NULL,
      new_code_hash VARCHAR(255) NOT NULL,
      expires_at DATETIME NOT NULL,
      attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
      used_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_email_change_user (user_id, used_at, created_at),
      INDEX idx_email_change_expires (expires_at),
      CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    db()->exec("DELETE FROM email_change_requests
        WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
           OR expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $done = true;
}
