<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$user = current_user();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$dueSoonCount = 0;
$notificationCount = 0;
$headerNotifications = [];
$deadlineAlertTasks = [];
if ($user) {
    try {
        ensure_v23_schema();
        $dueSoon = db()->prepare("SELECT COUNT(*) FROM tasks WHERE archived_at IS NULL AND status<>'done' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND (is_private=0 OR creator_id=?)");
        $dueSoon->execute([(int)$user['id']]);
        $dueSoonCount = (int)$dueSoon->fetchColumn();
        ensure_v22_schema();
        $notificationCounter = db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND read_at IS NULL');
        $notificationCounter->execute([(int)$user['id']]);
        $notificationCount = (int)$notificationCounter->fetchColumn();
        $notificationList = db()->prepare('SELECT n.*,u.name actor_name FROM notifications n LEFT JOIN users u ON u.id=n.actor_id WHERE n.recipient_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT 6');
        $notificationList->execute([(int)$user['id']]);
        $headerNotifications = $notificationList->fetchAll();
        $deadlineAlert = db()->prepare("SELECT t.id,t.title,t.due_date,t.priority
            FROM tasks t
            WHERE t.archived_at IS NULL
              AND t.status<>'done'
              AND t.due_date IN (CURDATE(),DATE_ADD(CURDATE(),INTERVAL 1 DAY))
              AND (t.creator_id=? OR EXISTS(SELECT 1 FROM task_assignees tad WHERE tad.task_id=t.id AND tad.user_id=?))
              AND (t.is_private=0 OR t.creator_id=?)
            ORDER BY t.due_date,FIELD(t.priority,'urgent','high','medium','low'),t.title");
        $deadlineAlert->execute([(int)$user['id'],(int)$user['id'],(int)$user['id']]);
        $deadlineAlertTasks = $deadlineAlert->fetchAll();
    } catch (Throwable $e) {
        $dueSoonCount = 0;
        $notificationCount = 0;
        $headerNotifications = [];
        $deadlineAlertTasks = [];
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? APP_NAME) ?> - <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=20261006-archive-alerts">
    <link rel="stylesheet" href="assets/css/profile-email.css?v=20261002-5">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261006-archive-alerts">
    <script src="assets/js/theme.js?v=20261006-header-controls"></script>
    <script src="assets/js/accessibility.js?v=20261006-header-controls"></script>
</head>
<body>
<header class="topbar">
    <div class="brand"><a href="dashboard.php"><i class="bi bi-check2-square" aria-hidden="true"></i> <?= APP_NAME ?></a></div>
    <?php if ($user): ?>
    <nav>
        <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <a class="<?= $currentPage === 'reminders.php' ? 'active' : '' ?>" href="reminders.php">Lembretes</a>
        <a class="<?= in_array($currentPage, ['task_form.php','task.php'], true) ? 'active' : '' ?>" href="task_form.php">Tarefas</a>
        <a class="<?= $currentPage === 'archived.php' ? 'active' : '' ?>" href="archived.php">Arquivados</a>
        <a class="<?= $currentPage === 'tutorial.php' ? 'active' : '' ?>" href="tutorial.php">Ajuda</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a class="<?= $currentPage === 'users.php' ? 'active' : '' ?>" href="users.php">Usuários</a><?php endif; ?>
    </nav>
    <div class="userbox">
        <div class="notification-menu" data-notification-menu>
            <button class="notification-bell" type="button" aria-label="Abrir notificações" aria-expanded="false" data-notification-toggle>
                <i class="bi bi-bell-fill" aria-hidden="true"></i>
                <span class="notification-badge<?= $notificationCount ? '' : ' is-empty' ?>" data-notification-badge><?= $notificationCount ?></span>
            </button>
            <div class="notification-dropdown" data-notification-dropdown hidden>
                <div class="notification-dropdown-head"><strong>Notificações</strong><span><?= $notificationCount ?> nova<?= $notificationCount === 1 ? '' : 's' ?></span></div>
                <div class="notification-dropdown-list">
                <?php if (!$headerNotifications): ?>
                    <div class="notification-empty">Nenhuma notificação.</div>
                <?php else: foreach ($headerNotifications as $notification): ?>
                    <div class="notification-preview<?= empty($notification['read_at']) ? ' is-unread' : '' ?>">
                        <a href="notifications.php?view=<?= $notification['id'] ?>">
                            <strong><?= e($notification['actor_name'] ?: 'Sistema') ?> <?= e($notification['action']) ?></strong>
                            <?php if (!empty($notification['details'])): ?><span><?= e($notification['details']) ?></span><?php endif; ?>
                            <small><?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?></small>
                        </a>
                        <form method="post" action="notifications.php" class="notification-delete-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_one">
                            <input type="hidden" name="id" value="<?= $notification['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'dashboard.php') ?>">
                            <button type="submit" title="Apagar esta notificação" aria-label="Apagar esta notificação"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
                </div>
                <?php if ($dueSoonCount > 0): ?><a class="notification-due-link" href="task_form.php?filter=due_soon"><i class="bi bi-alarm" aria-hidden="true"></i> <?= $dueSoonCount ?> tarefa<?= $dueSoonCount === 1 ? '' : 's' ?> próxima<?= $dueSoonCount === 1 ? '' : 's' ?> do prazo</a><?php endif; ?>
                <div class="notification-dropdown-actions">
                    <a class="btn small secondary" href="notifications.php">Ver notificações</a>
                    <form method="post" action="notifications.php" onsubmit="return confirm('Limpar todas as suas notificações?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear_all">
                        <button class="btn small secondary" type="submit" <?= $headerNotifications ? '' : 'disabled' ?>>Limpar todas</button>
                    </form>
                </div>
            </div>
        </div>
        <a class="profile-link" href="profile.php" title="Abrir meu perfil"><span class="profile-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span><span><?= e($user['name']) ?></span></a>
        <a href="logout.php">Sair</a>
    </div>
    <?php endif; ?>
</header>
<div class="font-size-widget" data-font-size-widget>
    <button class="font-size-trigger" type="button" aria-label="Ajustar tamanho do texto" title="Ajustar tamanho do texto" aria-expanded="false" data-font-size-toggle>
        <i class="bi bi-fonts" aria-hidden="true"></i>
        <span>Aa</span>
    </button>
    <div class="font-size-panel" role="group" aria-label="Controles de tamanho do texto" data-font-size-panel hidden>
        <strong>Tamanho do texto</strong>
        <div class="font-size-actions">
            <button type="button" aria-label="Diminuir tamanho do texto" title="Diminuir texto" data-font-size-decrease>A−</button>
            <button type="button" class="font-size-reset" aria-label="Restaurar tamanho padrão" title="Restaurar tamanho padrão" data-font-size-reset><span data-font-size-value>100%</span></button>
            <button type="button" aria-label="Aumentar tamanho do texto" title="Aumentar texto" data-font-size-increase>A+</button>
        </div>
    </div>
</div>
<?php if ($user && $deadlineAlertTasks):
    $deadlineAlertSignature = (int)$user['id'] . '-' . date('Y-m-d') . '-' . implode('-', array_column($deadlineAlertTasks, 'id'));
?>
<div class="modal fade deadline-alert-modal" id="deadlineAlertModal" tabindex="-1" aria-labelledby="deadlineAlertModalTitle" aria-hidden="true" data-deadline-alert-modal data-alert-signature="<?= e($deadlineAlertSignature) ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="deadline-alert-kicker">Atenção ao prazo</span><h2 class="modal-title" id="deadlineAlertModalTitle"><i class="bi bi-alarm" aria-hidden="true"></i> Tarefas próximas do vencimento</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p>Estas são somente as suas tarefas ou tarefas em que você foi indicado como responsável.</p>
                <div class="deadline-alert-list">
                <?php foreach ($deadlineAlertTasks as $alertTask): $isDueToday = $alertTask['due_date'] === date('Y-m-d'); ?>
                    <a class="deadline-alert-item<?= $isDueToday ? ' is-today' : '' ?>" href="task.php?id=<?= (int)$alertTask['id'] ?>">
                        <span class="deadline-alert-icon"><i class="bi <?= $isDueToday ? 'bi-exclamation-triangle-fill' : 'bi-clock-fill' ?>" aria-hidden="true"></i></span>
                        <span><strong><?= e($alertTask['title']) ?></strong><small><?= $isDueToday ? 'Vence hoje' : 'Vence amanhã' ?> • <?= date('d/m/Y', strtotime($alertTask['due_date'])) ?></small></span>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn secondary" data-bs-dismiss="modal">Entendi</button><a class="btn" href="task_form.php?filter=due_alert">Ver tarefas</a></div>
        </div>
    </div>
</div>
<?php endif; ?>
<main class="container">
<?php $flash = take_flash(); if ($flash): ?>
    <div class="flash flash-toast flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
        <span class="flash-icon" aria-hidden="true"><i class="bi <?= ['success'=>'bi-check-lg','error'=>'bi-x-lg','warning'=>'bi-exclamation-lg','info'=>'bi-info-lg'][$flash['type']] ?>"></i></span>
        <span><?= e($flash['message']) ?></span>
        <button type="button" class="flash-close" aria-label="Fechar mensagem"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
<?php endif; ?>
