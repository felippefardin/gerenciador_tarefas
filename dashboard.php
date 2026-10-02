<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v4_schema();
ensure_v6_schema();
ensure_v8_schema();
ensure_v14_schema();
ensure_v17_schema();

$pdo = db();
$currentUserId = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_reminder') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT id,user_id,title,completed,is_private FROM simple_tasks WHERE id=? AND (is_private=0 OR user_id=?)');
    $stmt->execute([$id, $currentUserId]);
    $reminder = $stmt->fetch();

    if ($reminder && can_manage_reminder($reminder,$currentUserId)) {
        $newCompleted = (int)!((bool)$reminder['completed']);
        $stmt = $pdo->prepare('UPDATE simple_tasks SET completed=?, completed_at=? WHERE id=?');
        $stmt->execute([$newCompleted, $newCompleted ? date('Y-m-d H:i:s') : null, $id]);
        log_activity($newCompleted ? 'concluiu um lembrete' : 'reabriu um lembrete', 'simple_task', $id, $reminder['title']);
        $_SESSION['flash'] = $newCompleted ? 'Lembrete concluído.' : 'Lembrete reaberto.';
    }

    redirect('dashboard.php');
}

$stats = [
    'todo' => 0, 'doing' => 0, 'review' => 0, 'done' => 0, 'overdue' => 0, 'reminders' => 0,
];
$countTask = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status=? AND (is_private=0 OR creator_id=?)");
foreach (['todo','doing','review','done'] as $status) { $countTask->execute([$status,$currentUserId]); $stats[$status]=(int)$countTask->fetchColumn(); }
$countOverdue = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE due_date<CURDATE() AND status<>'done' AND (is_private=0 OR creator_id=?)");
$countOverdue->execute([$currentUserId]); $stats['overdue']=(int)$countOverdue->fetchColumn();
$countReminders = $pdo->prepare('SELECT COUNT(*) FROM simple_tasks WHERE completed=0 AND (is_private=0 OR user_id=?)');
$countReminders->execute([$currentUserId]); $stats['reminders']=(int)$countReminders->fetchColumn();

$stmt = $pdo->prepare("SELECT t.*, " . task_assignee_names_sql('t') . " assignee_names, c.name creator_name FROM tasks t JOIN users c ON c.id=t.creator_id WHERE t.is_private=0 OR t.creator_id=? ORDER BY t.updated_at DESC LIMIT 8");
$stmt->execute([$currentUserId]); $recent = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT s.*, u.name creator_name," . reminder_assignee_names_sql('s') . " assignee_names,EXISTS(SELECT 1 FROM reminder_assignees rad WHERE rad.reminder_id=s.id AND rad.user_id=?) is_assignee
    FROM simple_tasks s
    JOIN users u ON u.id=s.user_id
    WHERE s.completed=0 AND (s.is_private=0 OR s.user_id=?)
    ORDER BY CASE WHEN s.due_date IS NULL THEN 1 ELSE 0 END, s.due_date ASC, s.created_at DESC
    LIMIT 8");
$stmt->execute([$currentUserId,$currentUserId]); $dashboardReminders = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT t.id,t.title,t.due_date,t.priority," . task_assignee_names_sql('t') . " assignee_names
    FROM tasks t
    WHERE t.status<>'done'
      AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
      AND (t.is_private=0 OR t.creator_id=?)
    ORDER BY t.due_date, FIELD(t.priority,'urgent','high','medium','low')
    LIMIT 6");
$stmt->execute([$currentUserId]);
$upcomingTasks = $stmt->fetchAll();

$totalTasks = $stats['todo'] + $stats['doing'] + $stats['review'] + $stats['done'];
$pendingTasks = $stats['todo'] + $stats['doing'] + $stats['review'];
$completionRate = $totalTasks ? (int)round(($stats['done'] / $totalTasks) * 100) : 0;
$chartPercent = static fn(int $value): int => $totalTasks ? (int)round(($value / $totalTasks) * 100) : 0;

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Visão geral compartilhada das tarefas e dos lembretes.</p>
    </div>
    <div class="page-actions">
        <a class="btn secondary" href="reminders.php">✓ Lembretes</a>
        <a class="btn" href="task_form.php">+ Nova tarefa</a>
    </div>
</div>

<div class="stats-grid">
<?php foreach ([
    ['A fazer',$stats['todo']],
    ['Em andamento',$stats['doing']],
    ['Em revisão',$stats['review']],
    ['Concluídas',$stats['done']],
    ['Atrasadas',$stats['overdue']],
    ['Lembretes pendentes',$stats['reminders']]
] as [$label,$value]): ?>
    <div class="stat-card"><span><?= e($label) ?></span><strong><?= $value ?></strong></div>
<?php endforeach; ?>
</div>

<section class="panel dashboard-chart-panel">
    <div class="panel-head"><div><h2>Progresso das tarefas</h2><span class="muted tiny"><?= $totalTasks ?> tarefa<?= $totalTasks === 1 ? '' : 's' ?> no total</span></div><strong class="completion-rate"><?= $completionRate ?>% concluído</strong></div>
    <div class="dashboard-chart-layout">
        <div class="donut-chart" style="--completion: <?= $completionRate ?>" role="img" aria-label="<?= $completionRate ?> por cento das tarefas concluídas"><span><strong><?= $stats['done'] ?></strong><small>de <?= $totalTasks ?></small></span></div>
        <div class="bar-chart">
            <?php foreach ([['A fazer','todo'],['Em andamento','doing'],['Em revisão','review'],['Concluídas','done']] as [$label,$key]): ?>
            <div class="chart-row"><div><span><?= e($label) ?></span><strong><?= $stats[$key] ?></strong></div><div class="chart-track"><span class="chart-fill chart-<?= e($key) ?>" style="width:<?= $chartPercent($stats[$key]) ?>%"></span></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($upcomingTasks): ?>
<section class="panel deadline-notifications">
    <div class="panel-head"><div><h2>🔔 Próximos vencimentos</h2><span class="muted tiny">Tarefas com prazo nos próximos 5 dias</span></div><a href="task_form.php?filter=due_soon">Ver todas</a></div>
    <div class="deadline-notification-list">
        <?php foreach ($upcomingTasks as $notice): $isToday = $notice['due_date'] === date('Y-m-d'); ?>
        <a href="task.php?id=<?= $notice['id'] ?>" class="deadline-notification-item <?= $isToday ? 'is-today' : '' ?>">
            <span class="priority <?= e($notice['priority']) ?>"><?= e(priority_label($notice['priority'])) ?></span>
            <span class="deadline-notification-title"><?= e($notice['title']) ?><small><?= e($notice['assignee_names'] ?: 'Sem responsáveis') ?></small></span>
            <strong><?= $isToday ? 'Hoje' : date('d/m', strtotime($notice['due_date'])) ?></strong>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="two-col">
    <section class="panel">
        <div class="panel-head"><h2>Tarefas recentes</h2><a href="task_form.php">Ver tarefas</a></div>
        <?php if(!$recent): ?>
            <p class="empty">Nenhuma tarefa criada.</p>
        <?php else: ?>
            <div class="list">
            <?php foreach($recent as $t): ?>
                <a class="list-row" href="task.php?id=<?= $t['id'] ?>">
                    <div><strong><?= e($t['title']) ?></strong><small><?= !empty($t['is_private']) ? '🔒 Privada • ' : '' ?>Criada por <?= e($t['creator_name']) ?> • <?= e($t['assignee_names'] ?: 'Sem responsáveis') ?></small></div>
                    <span class="badge <?= e($t['status']) ?>"><?= e(task_status_label($t['status'])) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel dashboard-reminders-panel">
        <div class="panel-head">
            <div><h2>Lembretes</h2><span class="muted tiny"><?= $stats['reminders'] ?> pendente<?= $stats['reminders'] === 1 ? '' : 's' ?></span></div>
            <a href="reminders.php">Ver todos</a>
        </div>
        <?php if (!$dashboardReminders): ?>
            <div class="dashboard-reminder-empty"><div class="reminder-empty-check">✓</div><strong>Nenhum lembrete pendente</strong><span>Os lembretes compartilhados aparecerão aqui.</span></div>
        <?php else: ?>
            <div class="dashboard-reminder-list">
            <?php foreach ($dashboardReminders as $item):
                $isOverdue = $item['due_date'] && $item['due_date'] < date('Y-m-d');
                $isToday = $item['due_date'] === date('Y-m-d');
            ?>
                <?php $deadlineClass = reminder_deadline_class($item['due_date']); ?>
                <div class="dashboard-reminder-item <?= e($deadlineClass) ?><?= $isOverdue ? ' overdue' : '' ?>">
                    <?php $isOwner = (int)$item['user_id'] === $currentUserId || (empty($item['is_private']) && !empty($item['is_assignee'])); ?>
                    <?php if ($isOwner): ?><form method="post" class="reminder-toggle-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_reminder">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="reminder-check" title="Marcar como concluído" aria-label="Marcar como concluído"></button>
                    </form><?php else: ?><span class="reminder-check reminder-check-readonly"></span><?php endif; ?>
                    <div class="dashboard-reminder-content">
                        <div class="reminder-title"><?= e($item['title']) ?></div>
                        <div class="reminder-date">
                            <?= !empty($item['is_private']) ? '🔒 ' : '' ?><?= e($item['creator_name']) ?><?php if (!empty($item['assignee_names'])): ?> • <?= e($item['assignee_names']) ?><?php endif; ?>
                            <?php if ($item['due_date']): ?> • <span class="<?= $isOverdue ? 'overdue-text' : ($isToday ? 'today-text' : '') ?>"><?= $isOverdue ? 'Atrasado • ' : ($isToday ? 'Hoje • ' : '') ?><?= date('d/m/Y', strtotime($item['due_date'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <a class="dashboard-reminder-edit" href="reminders.php#reminder-<?= $item['id'] ?>">Abrir</a>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
