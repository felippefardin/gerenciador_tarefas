<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v23_schema();

$pdo = db();
$user = current_user();
$allowedStatuses = ['todo','doing','review','done'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'todo');
    if (!in_array($status, $allowedStatuses, true)) $status = 'todo';

    $stmt = $pdo->prepare('SELECT id,title,creator_id,is_private FROM tasks WHERE id=? AND archived_at IS NOT NULL');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) {
        flash_message('Tarefa arquivada não encontrada.', 'error');
    } elseif (!can_manage_task($task, (int)$user['id'])) {
        flash_message('Você não possui permissão para restaurar esta tarefa.', 'error');
    } else {
        $stmt = $pdo->prepare('UPDATE tasks SET status=?,archived_at=NULL,archived_by=NULL WHERE id=?');
        $stmt->execute([$status, $id]);
        log_activity('restaurou a tarefa arquivada', 'task', $id, $task['title'] . ' → ' . task_status_label($status));
        flash_message('Tarefa restaurada para “' . task_status_label($status) . '”.', 'success');
    }
    redirect('archived.php');
}

$stmt = $pdo->prepare('SELECT t.*,p.name project_name,c.name creator_name,' . task_assignee_names_sql('t') . ' assignee_names,EXISTS(SELECT 1 FROM task_assignees taa WHERE taa.task_id=t.id AND taa.user_id=?) is_assignee FROM tasks t JOIN projects p ON p.id=t.project_id JOIN users c ON c.id=t.creator_id WHERE t.archived_at IS NOT NULL AND (t.is_private=0 OR t.creator_id=?) ORDER BY t.archived_at DESC,t.id DESC');
$stmt->execute([(int)$user['id'], (int)$user['id']]);
$tasks = $stmt->fetchAll();

$pageTitle = 'Arquivados';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head archived-page-head">
    <div>
        <span class="task-page-eyebrow">Histórico seguro</span>
        <h1><i class="bi bi-archive-fill" aria-hidden="true"></i> Arquivados</h1>
        <p class="muted">Tarefas concluídas armazenadas fora do quadro ativo. Nada é excluído automaticamente.</p>
    </div>
    <a class="btn secondary" href="task_form.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Voltar às tarefas</a>
</div>

<?php if (!$tasks): ?>
<section class="panel archived-empty-state">
    <span><i class="bi bi-archive" aria-hidden="true"></i></span>
    <h2>Nenhuma tarefa arquivada</h2>
    <p class="muted">Quando você arquivar uma tarefa concluída, ela aparecerá aqui.</p>
</section>
<?php else: ?>
<div class="archived-summary"><strong><?= count($tasks) ?></strong> tarefa<?= count($tasks) === 1 ? '' : 's' ?> armazenada<?= count($tasks) === 1 ? '' : 's' ?></div>
<div class="archived-grid">
<?php foreach ($tasks as $task):
    $canManage = (int)$task['creator_id'] === (int)$user['id'] || (empty($task['is_private']) && !empty($task['is_assignee']));
?>
<article class="panel archived-card">
    <div class="archived-card-head">
        <div>
            <span class="priority <?= e($task['priority']) ?>"><?= e(priority_label($task['priority'])) ?></span>
            <h2><?= e($task['title']) ?></h2>
        </div>
        <span class="archived-date"><i class="bi bi-archive" aria-hidden="true"></i> <?= date('d/m/Y H:i', strtotime($task['archived_at'])) ?></span>
    </div>
    <p class="archived-description"><?= nl2br(e($task['description'] ?: 'Sem descrição.')) ?></p>
    <div class="archived-meta">
        <span><i class="bi bi-folder2-open" aria-hidden="true"></i> <?= e($task['project_name']) ?></span>
        <span><i class="bi bi-person" aria-hidden="true"></i> <?= e($task['assignee_names'] ?: $task['creator_name']) ?></span>
        <?php if ($task['due_date']): ?><span><i class="bi bi-calendar-event" aria-hidden="true"></i> Prazo: <?= date('d/m/Y', strtotime($task['due_date'])) ?></span><?php endif; ?>
        <span><i class="bi <?= !empty($task['is_private']) ? 'bi-lock-fill' : 'bi-people-fill' ?>" aria-hidden="true"></i> <?= !empty($task['is_private']) ? 'Privada' : 'Pública' ?></span>
    </div>
    <?php if ($canManage): ?>
    <div class="archived-actions">
        <form method="post" class="archived-restore-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <label for="restore-status-<?= (int)$task['id'] ?>">Restaurar para</label>
            <select id="restore-status-<?= (int)$task['id'] ?>" name="status">
                <option value="todo">A fazer</option>
                <option value="doing">Em andamento</option>
                <option value="review">Em revisão</option>
                <option value="done" selected>Concluídos</option>
            </select>
            <button class="btn" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar</button>
        </form>
        <form method="post" action="delete_task.php" onsubmit="return confirm('Excluir definitivamente esta tarefa? Comentários, subtarefas e anexos também serão apagados e esta ação não poderá ser desfeita.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <input type="hidden" name="return_to" value="archived.php">
            <button class="btn danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Excluir definitivamente</button>
        </form>
    </div>
    <?php else: ?><p class="muted tiny archived-readonly"><i class="bi bi-eye" aria-hidden="true"></i> Somente o criador ou um responsável pode restaurar ou excluir.</p><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
