<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user !== null) return $user;

    ensure_v23_schema();
    $stmt = db()->prepare('SELECT id, name, username, email, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function log_activity(string $action, string $entityType, ?int $entityId = null, ?string $details = null): void
{
    $user = current_user();
    if (!$user) return;
    $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user['id'], $action, $entityType, $entityId, $details]);

    try {
        ensure_v22_schema();
        $recipientIds = [];
        $link = null;

        if ($entityType === 'task' && $entityId) {
            $entity = db()->prepare('SELECT id,project_id,creator_id,is_private,archived_at FROM tasks WHERE id=?');
            $entity->execute([$entityId]);
            $task = $entity->fetch();
            if ($task) {
                $recipientIds = [(int)$task['creator_id']];
                if (empty($task['is_private'])) {
                    $recipientIds = array_merge($recipientIds, project_notification_recipient_ids((int)$task['project_id']));
                }
                $link = $action === 'apagou a tarefa' ? 'task_form.php' : (!empty($task['archived_at']) ? 'archived.php' : 'task.php?id=' . (int)$entityId);
            }
        } elseif ($entityType === 'simple_task' && $entityId) {
            $entity = db()->prepare('SELECT id,user_id,is_private FROM simple_tasks WHERE id=?');
            $entity->execute([$entityId]);
            $reminder = $entity->fetch();
            if ($reminder) {
                $recipientIds = [(int)$reminder['user_id']];
                if (empty($reminder['is_private'])) {
                    $mentions = db()->prepare('SELECT user_id FROM reminder_assignees WHERE reminder_id=?');
                    $mentions->execute([$entityId]);
                    $recipientIds = array_merge($recipientIds, array_map('intval', $mentions->fetchAll(PDO::FETCH_COLUMN)));
                }
                $link = $action === 'excluiu um lembrete' ? 'reminders.php' : 'reminders.php#reminder-' . (int)$entityId;
            }
        } elseif ($entityType === 'project' && $entityId) {
            $exists = db()->prepare('SELECT owner_id FROM projects WHERE id=?');
            $exists->execute([$entityId]);
            $ownerId = (int)$exists->fetchColumn();
            if ($ownerId) {
                $recipientIds = [$ownerId];
                $link = $action === 'removeu o projeto' ? 'projects.php' : 'project.php?id=' . (int)$entityId;
            }
        }

        // Uma notificação por destinatário, inclusive para o autor quando envolvido.
        // As tarefas privadas mantêm a mesma restrição de acesso do sistema.
        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn($id) => $id > 0)));
        if ($recipientIds) {
            $notify = db()->prepare('INSERT INTO notifications (recipient_id,actor_id,action,entity_type,entity_id,details,link) VALUES (?,?,?,?,?,?,?)');
            foreach ($recipientIds as $recipientId) {
                $notify->execute([$recipientId,(int)$user['id'],$action,$entityType,$entityId,$details,$link]);
            }
        }
    } catch (Throwable $e) {
        // Uma falha na notificação nunca deve interromper a ação principal do usuário.
        error_log('Falha ao gerar notificações: ' . $e->getMessage());
    }
}

/** Envolvidos identificados pelos vínculos já existentes, sem migrar registros. */
function project_notification_recipient_ids(int $projectId): array
{
    $stmt = db()->prepare('
        SELECT owner_id AS user_id FROM projects WHERE id=?
        UNION
        SELECT creator_id FROM tasks WHERE project_id=? AND is_private=0
        UNION
        SELECT assignee_id FROM tasks WHERE project_id=? AND is_private=0 AND assignee_id IS NOT NULL
        UNION
        SELECT ta.user_id FROM task_assignees ta
            JOIN tasks t ON t.id=ta.task_id WHERE t.project_id=? AND t.is_private=0
        UNION
        SELECT c.user_id FROM comments c
            JOIN tasks t ON t.id=c.task_id WHERE t.project_id=? AND t.is_private=0
        UNION
        SELECT a.user_id FROM attachments a
            JOIN tasks t ON t.id=a.task_id WHERE t.project_id=? AND t.is_private=0
        UNION
        SELECT ci.user_id FROM comment_images ci
            JOIN comments c ON c.id=ci.comment_id
            JOIN tasks t ON t.id=c.task_id WHERE t.project_id=? AND t.is_private=0
    ');
    $stmt->execute(array_fill(0, 7, $projectId));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
