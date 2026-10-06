<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v22_schema();

$pdo = db();
$userId = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?');
        $stmt->execute([$userId]);
        if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'delete_one') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE id=? AND recipient_id=?');
        $stmt->execute([$id,$userId]);
        flash_message($stmt->rowCount() ? 'Notificação apagada.' : 'Notificação não encontrada.', $stmt->rowCount() ? 'success' : 'info');
    } elseif ($action === 'clear_all') {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE recipient_id=?');
        $stmt->execute([$userId]);
        flash_message($stmt->rowCount() ? 'Todas as notificações foram removidas.' : 'Não há notificações para limpar.', $stmt->rowCount() ? 'success' : 'info');
    }

    $returnTo = (string)($_POST['return_to'] ?? 'notifications.php');
    if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?=&%#]+$/', $returnTo) || str_contains($returnTo, '..')) $returnTo = 'notifications.php';
    redirect($returnTo);
}

if (isset($_GET['view'])) {
    $id = (int)$_GET['view'];
    $stmt = $pdo->prepare('SELECT link FROM notifications WHERE id=? AND recipient_id=?');
    $stmt->execute([$id,$userId]);
    $link = $stmt->fetchColumn();
    if ($link !== false) {
        $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?')->execute([$id,$userId]);
        redirect($link ?: 'notifications.php');
    }
    redirect('notifications.php');
}

// Abrir a central conta como visualização: remove o destaque, mas preserva as mensagens.
$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE recipient_id=?')->execute([$userId]);
$stmt = $pdo->prepare('SELECT n.*,u.name actor_name FROM notifications n LEFT JOIN users u ON u.id=n.actor_id WHERE n.recipient_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT 100');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notificações';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head notification-page-head">
    <div>
        <span class="task-page-eyebrow">Central de novidades</span>
        <h1>Notificações</h1>
        <p class="muted">Visualizar uma notificação remove apenas o destaque de nova. Ela continua aqui até ser apagada.</p>
    </div>
    <?php if ($notifications): ?>
    <form method="post" onsubmit="return confirm('Limpar todas as suas notificações?');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear_all">
        <button class="btn secondary" type="submit">Limpar todas</button>
    </form>
    <?php endif; ?>
</div>

<section class="panel notification-center">
<?php if (!$notifications): ?>
    <div class="notification-center-empty"><span><i class="bi bi-bell" aria-hidden="true"></i></span><strong>Nenhuma notificação</strong><p>As novidades de tarefas, lembretes compartilhados e projetos aparecerão aqui.</p></div>
<?php else: ?>
    <div class="notification-center-list">
    <?php foreach ($notifications as $notification): ?>
        <article class="notification-center-item">
            <a class="notification-center-content" href="<?= e($notification['link'] ?: '#') ?>">
                <span class="notification-center-icon"><i class="bi bi-bell-fill" aria-hidden="true"></i></span>
                <span>
                    <strong><?= e($notification['actor_name'] ?: 'Sistema') ?> <?= e($notification['action']) ?></strong>
                    <?php if (!empty($notification['details'])): ?><span><?= e($notification['details']) ?></span><?php endif; ?>
                    <small><?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?> • Visualizada</small>
                </span>
            </a>
            <form method="post" onsubmit="return confirm('Apagar esta notificação?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_one">
                <input type="hidden" name="id" value="<?= $notification['id'] ?>">
                <button class="notification-delete-one" type="submit" title="Apagar esta notificação"><i class="bi bi-trash3" aria-hidden="true"></i> Apagar</button>
            </form>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
