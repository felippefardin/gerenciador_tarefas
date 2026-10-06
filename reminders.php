<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v4_schema();
ensure_v6_schema();
ensure_v8_schema();
ensure_v17_schema();

$pdo = db();
$user = current_user();
$userId = (int)$user['id'];
$error = '';
$users = $pdo->query('SELECT id,name FROM users ORDER BY name')->fetchAll();
$normalizeAssignees = static function (array $values) use ($pdo): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $values), fn($id) => $id > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$saveAssignees = static function (int $reminderId, array $ids) use ($pdo): void {
    $pdo->prepare('DELETE FROM reminder_assignees WHERE reminder_id=?')->execute([$reminderId]);
    $stmt = $pdo->prepare('INSERT INTO reminder_assignees(reminder_id,user_id) VALUES(?,?)');
    foreach ($ids as $id) $stmt->execute([$reminderId, $id]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $dueDate = trim($_POST['due_date'] ?? '') ?: null;
        $isPrivate = isset($_POST['is_shared']) ? 0 : 1;
        $assignees = $isPrivate ? [] : $normalizeAssignees((array)($_POST['assignee_ids'] ?? []));
        if ($title === '') {
            $error = 'Digite o lembrete antes de adicionar.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO simple_tasks (user_id,title,due_date,is_private) VALUES (?,?,?,?)');
            $stmt->execute([$userId, $title, $dueDate, $isPrivate]);
            $id = (int)$pdo->lastInsertId();
            $saveAssignees($id, $assignees);
            log_activity('criou um lembrete', 'simple_task', $id, $title);
            $_SESSION['flash'] = $isPrivate ? 'Lembrete pessoal adicionado.' : 'Lembrete compartilhado com todos os usuários.';
            redirect('reminders.php');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id,user_id,title,completed,is_private FROM simple_tasks WHERE id=? AND (is_private=0 OR user_id=?)');
        $stmt->execute([$id, $userId]);
        $item = $stmt->fetch();
        if ($item && can_manage_reminder($item, $userId)) {
            $newCompleted = (int)!((bool)$item['completed']);
            $stmt = $pdo->prepare('UPDATE simple_tasks SET completed=?, completed_at=? WHERE id=?');
            $stmt->execute([$newCompleted, $newCompleted ? date('Y-m-d H:i:s') : null, $id]);
            log_activity($newCompleted ? 'concluiu um lembrete' : 'reabriu um lembrete', 'simple_task', $id, $item['title']);
            $_SESSION['flash'] = $newCompleted ? 'Lembrete concluído.' : 'Lembrete reaberto.';
        }
        redirect('reminders.php');
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $dueDate = trim($_POST['due_date'] ?? '') ?: null;
        $isPrivate = isset($_POST['is_shared']) ? 0 : 1;
        $assignees = $isPrivate ? [] : $normalizeAssignees((array)($_POST['assignee_ids'] ?? []));
        if ($title === '') {
            flash_message('Não foi possível salvar: o lembrete não pode ficar vazio.', 'error');
        } else {
            $check = $pdo->prepare('SELECT id,user_id,is_private FROM simple_tasks WHERE id=? AND (is_private=0 OR user_id=?)');
            $check->execute([$id,$userId]);
            $item = $check->fetch();
            if ($item && can_manage_reminder($item,$userId)) {
                $stmt = $pdo->prepare('UPDATE simple_tasks SET title=?, due_date=?, is_private=? WHERE id=?');
                $stmt->execute([$title,$dueDate,$isPrivate,$id]);
                $saveAssignees($id,$assignees);
                log_activity('editou um lembrete', 'simple_task', $id, $title);
                $_SESSION['flash'] = 'Lembrete atualizado.';
            }
        }
        redirect('reminders.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id,user_id,title,is_private FROM simple_tasks WHERE id=? AND (is_private=0 OR user_id=?)');
        $stmt->execute([$id, $userId]);
        $item = $stmt->fetch();
        if ($item && can_manage_reminder($item,$userId)) {
            log_activity('excluiu um lembrete', 'simple_task', $id, $item['title']);
            $pdo->prepare('DELETE FROM simple_tasks WHERE id=?')->execute([$id]);
            $_SESSION['flash'] = 'Lembrete excluído.';
        }
        redirect('reminders.php');
    } elseif ($action === 'clear_completed') {
        $stmt = $pdo->prepare('DELETE FROM simple_tasks WHERE completed=1 AND user_id=?');
        $stmt->execute([$userId]);
        flash_message($stmt->rowCount() ? 'Lembretes concluídos removidos.' : 'Não há lembretes concluídos para remover.', $stmt->rowCount() ? 'success' : 'info');
        redirect('reminders.php');
    }
}

$stmt = $pdo->prepare("SELECT s.*, u.name creator_name," . reminder_assignee_names_sql('s') . " assignee_names,EXISTS(SELECT 1 FROM reminder_assignees ram WHERE ram.reminder_id=s.id AND ram.user_id=?) is_assignee FROM simple_tasks s JOIN users u ON u.id=s.user_id WHERE s.is_private=0 OR s.user_id=? ORDER BY s.completed ASC, CASE WHEN s.due_date IS NULL THEN 1 ELSE 0 END, s.due_date ASC, s.created_at DESC");
$stmt->execute([$userId,$userId]);
$items = $stmt->fetchAll();
$reminderAssigneeMap = [];
foreach ($pdo->query('SELECT reminder_id,user_id FROM reminder_assignees')->fetchAll() as $link) $reminderAssigneeMap[(int)$link['reminder_id']][] = (int)$link['user_id'];
$pending = array_values(array_filter($items, fn($i) => !(bool)$i['completed']));
$completed = array_values(array_filter($items, fn($i) => (bool)$i['completed']));
$today = date('Y-m-d');
$overdueCount = count(array_filter($pending, fn($i) => !empty($i['due_date']) && $i['due_date'] < $today));
$todayCount = count(array_filter($pending, fn($i) => ($i['due_date'] ?? '') === $today));

$pageTitle = 'Lembretes';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head reminder-page-head">
    <div>
        <span class="task-page-eyebrow">Agenda rápida</span>
        <h1>Lembretes</h1>
        <p class="muted">Seus lembretes são pessoais por padrão. Compartilhe somente quando todos precisarem visualizar.</p>
    </div>
    <div class="reminder-head-summary">
        <div><strong><?= count($pending) ?></strong><span>Pendentes</span></div>
        <div class="<?= $overdueCount ? 'has-overdue' : '' ?>"><strong><?= $overdueCount ?></strong><span>Atrasados</span></div>
        <div><strong><?= $todayCount ?></strong><span>Para hoje</span></div>
        <div><strong><?= count($completed) ?></strong><span>Concluídos</span></div>
    </div>
</div>

<section class="panel reminder-add-panel reminder-compose-card">
    <div class="reminder-compose-head">
        <div class="task-compose-heading"><span class="task-compose-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span><div><h2>Novo lembrete</h2><p>Registre um compromisso pessoal ou compartilhe com todos os usuários.</p></div></div>
        <span class="reminder-compose-hint">Preencha apenas o que for necessário</span>
    </div>
    <form method="post" class="reminder-add-form reminder-form-modern">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="reminder-add-main">
            <label for="reminder-title">O que precisa ser lembrado?</label>
            <input id="reminder-title" name="title" maxlength="255" placeholder="Ex.: Ligar para o fornecedor, enviar documento, conferir e-mail..." required autofocus>
        </div>
        <div class="reminder-date-field">
            <label for="reminder-date">Prazo</label>
            <input id="reminder-date" type="date" name="due_date">
        </div>
        <fieldset class="task-assignees-field reminder-assignees-field"><legend>Responsáveis</legend><div class="assignee-options"><?php foreach($users as $usr): ?><label class="assignee-option"><input type="checkbox" name="assignee_ids[]" value="<?= $usr['id'] ?>"><span><?= e($usr['name']) ?></span></label><?php endforeach; ?></div><small class="field-help">Somente os responsáveis selecionados receberão esta notificação.</small></fieldset>
        <label class="privacy-option reminder-privacy"><input type="checkbox" name="is_shared" value="1"><span><strong>Compartilhado</strong><small>Todos os usuários poderão visualizar</small></span></label>
        <button class="btn reminder-primary-action" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Criar lembrete</button>
    </form>
    <?php if ($error): ?><div class="error reminder-error"><?= e($error) ?></div><?php endif; ?>
</section>

<section class="panel reminder-section-card reminder-pending-card">
    <div class="panel-head reminder-panel-head">
        <div><span class="task-section-icon"><i class="bi bi-clock" aria-hidden="true"></i></span><div><h2>Pendentes</h2><span class="muted tiny">Você gerencia os seus lembretes; itens compartilhados também aparecem para os demais usuários.</span></div></div>
        <span class="task-count-pill"><?= count($pending) ?></span>
    </div>

    <?php if (!$pending): ?>
        <div class="reminder-empty"><div class="reminder-empty-check"><i class="bi bi-check-lg" aria-hidden="true"></i></div><strong>Tudo em dia</strong><span>Não há lembretes pendentes.</span></div>
    <?php else: ?>
        <div class="reminder-list">
        <?php foreach ($pending as $item):
            $isOverdue = $item['due_date'] && $item['due_date'] < date('Y-m-d');
            $isToday = $item['due_date'] === date('Y-m-d');
            $deadlineClass = reminder_deadline_class($item['due_date']);
        ?>
            <?php $isOwner = (int)$item['user_id'] === $userId; ?>
            <article class="reminder-item reminder-item-modern <?= e($deadlineClass) ?><?= $isOverdue ? ' overdue' : '' ?>" id="reminder-<?= $item['id'] ?>">
                <?php if ($isOwner): ?><form method="post" class="reminder-toggle-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <button type="submit" class="reminder-check" title="Marcar como concluído" aria-label="Marcar como concluído"></button>
                </form><?php else: ?><span class="reminder-check reminder-check-readonly"></span><?php endif; ?>
                <div class="reminder-content">
                    <div class="reminder-title"><?= e($item['title']) ?></div>
                    <div class="reminder-date">
                        Criado por <?= e($item['creator_name']) ?> • <?= !empty($item['is_private']) ? 'Pessoal' : 'Compartilhado' ?>
                        <?php if (!empty($item['assignee_names'])): ?> • Responsáveis: <?= e($item['assignee_names']) ?><?php endif; ?>
                        <?php if ($item['due_date']): ?> • <span class="<?= $isOverdue ? 'overdue-text' : ($isToday ? 'today-text' : '') ?>"><?= $isOverdue ? 'Atrasado • ' : ($isToday ? 'Hoje • ' : '') ?><?= date('d/m/Y', strtotime($item['due_date'])) ?></span><?php endif; ?>
                    </div>
                    <form method="post" class="reminder-edit-form" data-reminder-edit="<?= $item['id'] ?>" hidden>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <div class="reminder-edit-title"><label>Título do lembrete</label><input name="title" maxlength="255" value="<?= e($item['title']) ?>" required></div>
                        <div class="reminder-edit-date"><label>Prazo</label><input type="date" name="due_date" value="<?= e($item['due_date'] ?? '') ?>"></div>
                        <fieldset class="task-assignees-field"><legend>Responsáveis</legend><div class="assignee-options"><?php foreach($users as $usr): ?><label class="assignee-option"><input type="checkbox" name="assignee_ids[]" value="<?= $usr['id'] ?>" <?= in_array((int)$usr['id'],$reminderAssigneeMap[(int)$item['id']] ?? [],true) ? 'checked' : '' ?>><span><?= e($usr['name']) ?></span></label><?php endforeach; ?></div><small class="field-help">Somente os selecionados serão notificados.</small></fieldset>
                        <label class="privacy-option compact reminder-edit-privacy"><input type="checkbox" name="is_shared" value="1" <?= empty($item['is_private']) ? 'checked' : '' ?>><span>Compartilhado</span></label>
                        <div class="reminder-edit-actions">
                            <button class="btn small" type="submit">Salvar</button>
                            <button class="btn secondary small js-cancel-reminder-edit" type="button" data-id="<?= $item['id'] ?>">Cancelar</button>
                        </div>
                    </form>
                </div>
                <?php if ($isOwner): ?><div class="reminder-actions">
                    <button type="button" class="reminder-link reminder-action-edit js-edit-reminder" data-id="<?= $item['id'] ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i> Editar</button>
                    <form method="post" onsubmit="return confirm('Excluir este lembrete?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="reminder-link reminder-action-delete"><i class="bi bi-trash3" aria-hidden="true"></i> Excluir</button>
                    </form>
                </div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($completed): ?>
<section class="panel completed-reminders-panel reminder-section-card" id="completedRemindersPanel">
    <div class="panel-head reminder-panel-head">
        <div><span class="task-section-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><div><h2>Concluídos</h2><span class="muted tiny"><?= count($completed) ?> lembrete<?= count($completed) === 1 ? '' : 's' ?> finalizado<?= count($completed) === 1 ? '' : 's' ?></span></div></div>
        <div class="completed-head-actions"><button type="button" class="btn secondary small" id="toggleCompletedReminders" aria-expanded="true">Recolher</button><form method="post" onsubmit="return confirm('Remover todos os lembretes concluídos para todos os usuários?');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear_completed">
            <button class="btn secondary small" type="submit">Limpar concluídos</button>
        </form></div>
    </div>
    <div class="reminder-list completed-list">
    <?php foreach ($completed as $item): $isOwner = (int)$item['user_id'] === $userId; ?>
        <article class="reminder-item reminder-item-modern completed" id="reminder-<?= $item['id'] ?>">
            <?php if ($isOwner): ?><form method="post" class="reminder-toggle-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <button type="submit" class="reminder-check checked" title="Marcar novamente como pendente" aria-label="Marcar novamente como pendente"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
            </form><?php else: ?><span class="reminder-check checked reminder-check-readonly"><i class="bi bi-check-lg" aria-hidden="true"></i></span><?php endif; ?>
            <div class="reminder-content">
                <div class="reminder-title"><?= e($item['title']) ?></div>
                <div class="reminder-date">Criado por <?= e($item['creator_name']) ?> • <?= !empty($item['is_private']) ? 'Pessoal' : 'Compartilhado' ?><?php if (!empty($item['assignee_names'])): ?> • Responsáveis: <?= e($item['assignee_names']) ?><?php endif; ?><?= $item['completed_at'] ? ' • Concluído em ' . date('d/m/Y H:i', strtotime($item['completed_at'])) : '' ?></div>
            </div>
            <?php if ($isOwner): ?><div class="reminder-actions">
                <form method="post" onsubmit="return confirm('Excluir este lembrete?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <button type="submit" class="reminder-link reminder-action-delete"><i class="bi bi-trash3" aria-hidden="true"></i> Excluir</button>
                </form>
            </div><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script>
document.querySelectorAll('.js-edit-reminder').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.dataset.id;
        var item = document.getElementById('reminder-' + id);
        var form = item ? item.querySelector('[data-reminder-edit="' + id + '"]') : null;
        if (!item || !form) return;
        item.classList.add('editing');
        form.hidden = false;
        var input = form.querySelector('input[name="title"]');
        if (input) { input.focus(); input.select(); }
    });
});
document.querySelectorAll('.js-cancel-reminder-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.dataset.id;
        var item = document.getElementById('reminder-' + id);
        var form = item ? item.querySelector('[data-reminder-edit="' + id + '"]') : null;
        if (!item || !form) return;
        form.hidden = true;
        item.classList.remove('editing');
    });
});
var completedPanel = document.getElementById('completedRemindersPanel');
var completedToggle = document.getElementById('toggleCompletedReminders');
if (completedPanel && completedToggle) {
    var completedList = completedPanel.querySelector('.completed-list');
    completedToggle.addEventListener('click', function () {
        var collapsed = completedPanel.classList.toggle('is-collapsed');
        if (completedList) completedList.hidden = collapsed;
        completedToggle.textContent = collapsed ? 'Mostrar' : 'Recolher';
        completedToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
