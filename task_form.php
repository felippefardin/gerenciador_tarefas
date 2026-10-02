<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v8_schema();
ensure_v14_schema();
$pdo = db();
$user = current_user();
$task = null;
$id = (int)($_GET['id'] ?? 0);

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id=? AND (is_private=0 OR creator_id=?)');
    $stmt->execute([$id, $user['id']]);
    $task = $stmt->fetch();
    if (!$task) exit('Tarefa não encontrada.');
    if (!can_manage_task($task, (int)$user['id'])) { http_response_code(403); exit('Somente o criador ou um responsável pode editar esta tarefa pública.'); }
}
$selectedAssignees = [];
if ($id) {
    $selectedStmt = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');
    $selectedStmt->execute([$id]);
    $selectedAssignees = array_map('intval', $selectedStmt->fetchAll(PDO::FETCH_COLUMN));
}

function general_project_id(PDO $pdo, array $user): int {
    $stmt = $pdo->query("SELECT id FROM projects WHERE name='Tarefas gerais' ORDER BY id LIMIT 1");
    $projectId = (int)$stmt->fetchColumn();
    if ($projectId) return $projectId;
    $stmt = $pdo->prepare("INSERT INTO projects(name,description,owner_id,status) VALUES('Tarefas gerais','Categoria interna usada automaticamente pelo sistema.',?,'active')");
    $stmt->execute([(int)$user['id']]);
    return (int)$pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        flash_message('Não foi possível salvar: informe o título da tarefa.', 'error');
        redirect($id ? 'task_form.php?id=' . $id : 'task_form.php?new=1');
    }
    $description = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['todo','doing','review','done'], true) ? $_POST['status'] : 'todo';
    $priority = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent'], true) ? $_POST['priority'] : 'medium';
    $assignees = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['assignee_ids'] ?? [])), fn($value) => $value > 0)));
    if ($assignees) {
        $placeholders = implode(',', array_fill(0, count($assignees), '?'));
        $validStmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
        $validStmt->execute($assignees);
        $assignees = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $assignee = $assignees[0] ?? null;
    $due = trim($_POST['due_date'] ?? '') ?: null;
    $isPrivate = isset($_POST['is_private']) ? 1 : 0;
    $wasEditing = (bool)$task;
    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE tasks SET title=?,description=?,status=?,priority=?,assignee_id=?,due_date=?,is_private=? WHERE id=?');
            $stmt->execute([$title,$description,$status,$priority,$assignee,$due,$isPrivate,$id]);
            log_activity('atualizou a tarefa','task',$id,$title);
        } else {
            $projectId = general_project_id($pdo, $user);
            $stmt = $pdo->prepare('INSERT INTO tasks(project_id,title,description,status,priority,assignee_id,creator_id,due_date,is_private) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$projectId,$title,$description,$status,$priority,$assignee,$user['id'],$due,$isPrivate]);
            $id = (int)$pdo->lastInsertId();
            log_activity('criou a tarefa','task',$id,$title);
        }
        $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$id]);
        $linkAssignee = $pdo->prepare('INSERT INTO task_assignees(task_id,user_id) VALUES(?,?)');
        foreach ($assignees as $assigneeId) $linkAssignee->execute([$id, $assigneeId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_message('Não foi possível salvar a tarefa. Tente novamente.', 'error');
        redirect($wasEditing ? 'task_form.php?id=' . $id : 'task_form.php?new=1');
    }
    flash_message($wasEditing ? 'Tarefa atualizada com sucesso.' : 'Nova tarefa adicionada com sucesso.', 'success');
    redirect('task_form.php');
}

$users = $pdo->query('SELECT id,name FROM users ORDER BY name')->fetchAll();
$requestedFilter = (string)($_GET['filter'] ?? 'all');
$filter = in_array($requestedFilter, ['all','mine','overdue','today','done','due_soon'], true) ? $requestedFilter : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$requestedSort = (string)($_GET['sort'] ?? 'priority');
$sort = in_array($requestedSort, ['priority','due','assignee'], true) ? $requestedSort : 'priority';

$where = ['(t.is_private=0 OR t.creator_id=?)'];
$params = [(int)$user['id']];
if ($filter === 'mine') {
    $where[] = '(EXISTS(SELECT 1 FROM task_assignees tam WHERE tam.task_id=t.id AND tam.user_id=?) OR t.creator_id=?)';
    $params[] = (int)$user['id'];
    $params[] = (int)$user['id'];
} elseif ($filter === 'overdue') {
    $where[] = "t.due_date<CURDATE() AND t.status<>'done'";
} elseif ($filter === 'today') {
    $where[] = "t.due_date=CURDATE() AND t.status<>'done'";
} elseif ($filter === 'done') {
    $where[] = "t.status='done'";
} elseif ($filter === 'due_soon') {
    $where[] = "t.status<>'done' AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)";
}
if ($search !== '') {
    $where[] = '(t.title LIKE ? OR t.description LIKE ? OR EXISTS(SELECT 1 FROM task_assignees tas JOIN users uas ON uas.id=tas.user_id WHERE tas.task_id=t.id AND uas.name LIKE ?) OR c.name LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$orderBy = match ($sort) {
    'due' => 't.due_date IS NULL,t.due_date,FIELD(t.priority,"urgent","high","medium","low"),t.created_at DESC',
    'assignee' => 'assignee_names IS NULL,assignee_names,t.due_date IS NULL,t.due_date,t.created_at DESC',
    default => 'FIELD(t.priority,"urgent","high","medium","low"),t.due_date IS NULL,t.due_date,t.created_at DESC',
};
$sql = 'SELECT t.*,' . task_assignee_names_sql('t') . ' assignee_names,EXISTS(SELECT 1 FROM task_assignees tap WHERE tap.task_id=t.id AND tap.user_id=' . (int)$user['id'] . ') is_assignee,c.name creator_name FROM tasks t JOIN users c ON c.id=t.creator_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();
$groups = ['todo'=>[], 'doing'=>[], 'review'=>[], 'done'=>[]];
foreach ($tasks as $item) $groups[$item['status']][] = $item;
$totalVisible = count($tasks);
$activeVisible = count($groups['todo']) + count($groups['doing']) + count($groups['review']);
$overdueVisible = count(array_filter($tasks, fn($item) => !empty($item['due_date']) && $item['due_date'] < date('Y-m-d') && $item['status'] !== 'done'));
$pageTitle = $task ? 'Editar tarefa' : 'Tarefas';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head task-page-head">
    <div>
        <span class="task-page-eyebrow">Espaço de trabalho</span>
        <h1><?= $task ? 'Editar tarefa' : 'Tarefas' ?></h1>
        <p class="muted">Planeje, distribua responsabilidades e acompanhe o andamento em um só lugar.</p>
    </div>
    <div class="task-head-summary" aria-label="Resumo das tarefas visíveis">
        <div><strong><?= $totalVisible ?></strong><span>visíveis</span></div>
        <div><strong><?= $activeVisible ?></strong><span>ativas</span></div>
        <div class="<?= $overdueVisible ? 'has-overdue' : '' ?>"><strong><?= $overdueVisible ?></strong><span>atrasadas</span></div>
        <?php if ($task): ?><a class="btn secondary" href="task_form.php">Cancelar edição</a><?php else: ?><button class="btn task-head-cta" type="button" data-bs-toggle="modal" data-bs-target="#taskEditorModal">+ Adicionar nova tarefa</button><?php endif; ?>
    </div>
</div>
<div class="modal fade task-editor-modal" id="taskEditorModal" tabindex="-1" aria-labelledby="taskEditorModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
   <div class="modal-content task-compose-card">
    <div class="task-compose-head modal-header">
        <div class="task-compose-heading">
            <span class="task-compose-icon" aria-hidden="true">✓</span>
            <div><h2 id="taskEditorModalTitle"><?= $task ? 'Editar tarefa' : 'Nova tarefa' ?></h2><p>Preencha o essencial agora. Os detalhes podem ser atualizados depois.</p></div>
        </div>
        <button class="task-modal-close" type="button" data-bs-dismiss="modal" aria-label="Fechar">×</button>
    </div>
    <form method="post" class="task-create-form task-form-modern" id="taskCreateForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="task-form-title">
            <label for="task-title">O que precisa ser feito?</label>
            <input id="task-title" name="title" value="<?= e($task['title'] ?? '') ?>" placeholder="Ex.: Revisar contrato do fornecedor" required>
            <small>Use um título curto, claro e orientado à ação.</small>
        </div>
        <fieldset class="task-assignees-field task-form-assignees">
            <legend><span>Responsáveis</span><small>Selecione uma ou mais pessoas</small></legend>
            <div class="assignee-options">
                <?php foreach($users as $usr): ?>
                <label class="assignee-option"><input type="checkbox" name="assignee_ids[]" value="<?= $usr['id'] ?>" <?= in_array((int)$usr['id'], $selectedAssignees, true) ? 'checked' : '' ?>><span><?= e($usr['name']) ?></span></label>
                <?php endforeach; ?>
                <?php if (!$users): ?><span class="muted tiny">Nenhum usuário cadastrado.</span><?php endif; ?>
            </div>
        </fieldset>
        <div class="task-form-planning">
            <div><label for="task-due">Prazo</label><input id="task-due" type="date" name="due_date" value="<?= e($task['due_date'] ?? '') ?>"></div>
            <div><label for="task-status">Status inicial</label><select id="task-status" name="status"><?php foreach(['todo'=>'A Fazer','doing'=>'Em andamento','review'=>'Em revisão','done'=>'Concluído'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($task['status'] ?? 'todo') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div><label for="task-priority">Prioridade</label><select id="task-priority" name="priority"><?php foreach(['low'=>'Baixa','medium'=>'Média','high'=>'Alta','urgent'=>'Urgente'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($task['priority'] ?? 'medium') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="privacy-option task-form-privacy"><input type="checkbox" name="is_private" value="1" <?= !empty($task['is_private']) ? 'checked' : '' ?>><span><strong>Tarefa privada</strong><small>Somente você poderá visualizar este item</small></span></label>
        <div class="task-form-description"><label for="task-description">Descrição e instruções</label><textarea id="task-description" name="description" rows="4" placeholder="Inclua contexto, entregáveis, links ou observações importantes..."><?= e($task['description'] ?? '') ?></textarea></div>
        <div class="task-form-footer">
            <span class="task-form-hint">Campos com informações objetivas facilitam a busca e os alertas.</span>
            <div class="task-form-actions"><?php if ($task): ?><a class="btn secondary" href="task_form.php">Cancelar</a><?php endif; ?><button class="btn task-primary-action" type="submit"><?= $task ? 'Salvar alterações' : 'Criar tarefa' ?> <span aria-hidden="true">→</span></button></div>
        </div>
    </form>
   </div>
  </div>
</div>
<section class="task-tools task-control-panel" aria-label="Filtros de tarefas">
    <div class="task-controls-heading"><div><span class="task-control-icon" aria-hidden="true">⌕</span><div><strong>Organize seu quadro</strong><small>Filtre, pesquise e escolha a melhor ordem.</small></div></div></div>
    <div class="quick-filters task-filter-tabs">
        <?php foreach (['all'=>'Todas','mine'=>'Minhas tarefas','overdue'=>'Atrasadas','today'=>'Hoje','done'=>'Concluídas'] as $value=>$label):
            $filterUrl = 'task_form.php?' . http_build_query(array_filter(['filter'=>$value,'q'=>$search,'sort'=>$sort], fn($v) => $v !== ''));
        ?>
            <a class="filter-chip <?= $filter === $value ? 'active' : '' ?>" href="<?= e($filterUrl) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($filter === 'due_soon'): ?><a class="filter-chip active" href="task_form.php?filter=due_soon">Vencem em até 5 dias</a><?php endif; ?>
    </div>
    <form class="task-search-form task-modern-search" method="get">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <label class="sr-only" for="task-search">Pesquisar tarefas</label>
        <div class="task-search-input"><span aria-hidden="true">⌕</span><input id="task-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Pesquisar tarefa ou responsável..."></div>
        <label class="sr-only" for="task-sort">Ordenar tarefas</label>
        <select id="task-sort" name="sort">
            <option value="priority" <?= $sort === 'priority' ? 'selected' : '' ?>>Prioridade</option>
            <option value="due" <?= $sort === 'due' ? 'selected' : '' ?>>Prazo</option>
            <option value="assignee" <?= $sort === 'assignee' ? 'selected' : '' ?>>Responsável</option>
        </select>
        <button class="btn" type="submit">Aplicar</button>
        <?php if ($search !== '' || $filter !== 'all' || $sort !== 'priority'): ?><a class="btn secondary" href="task_form.php">Limpar</a><?php endif; ?>
    </form>
</section>
<div class="task-board-heading"><div><h2>Quadro de tarefas</h2><p><?= $totalVisible ?> resultado<?= $totalVisible === 1 ? '' : 's' ?> nesta visualização</p></div><span>Arraste os cartões para atualizar o status</span></div>
<?php if (!$tasks): ?><div class="panel task-empty-state"><div class="task-empty-icon" aria-hidden="true">✓</div><strong>Nenhuma tarefa encontrada</strong><span>Experimente limpar os filtros ou criar uma nova tarefa.</span><a class="btn secondary" href="task_form.php">Limpar visualização</a></div><?php endif; ?>
<div class="kanban task-board">
<?php foreach (['todo'=>'A Fazer','doing'=>'Em andamento','review'=>'Em revisão','done'=>'Concluído'] as $status=>$label): ?>
<section class="kanban-col status-<?= $status ?>" data-kanban-column="<?= $status ?>"><div class="kanban-head"><div class="kanban-title"><i aria-hidden="true"></i><h2><?= $label ?></h2></div><div class="kanban-head-actions"><span><?= count($groups[$status]) ?></span><?php if ($status === 'done'): ?><button type="button" class="collapse-completed" aria-expanded="true">Recolher</button><?php endif; ?></div></div><div class="dropzone" data-status="<?= $status ?>">
<?php foreach ($groups[$status] as $item): ?>
<?php $canManage = (int)$item['creator_id'] === (int)$user['id'] || (empty($item['is_private']) && !empty($item['is_assignee'])); ?>
<article class="task-card task-card-modern" draggable="<?= $canManage ? 'true' : 'false' ?>" data-task-id="<?= $item['id'] ?>"><a href="task.php?id=<?= $item['id'] ?>"><div class="task-meta"><span class="priority <?= e($item['priority']) ?>"><?= e(priority_label($item['priority'])) ?></span><span><?= !empty($item['is_private']) ? '🔒 Privada' : 'Pública' ?></span></div><h3><?= e($item['title']) ?></h3><div class="task-card-people"><span class="task-card-avatar" aria-hidden="true"><?= e(strtoupper(substr($item['assignee_names'] ?: $item['creator_name'], 0, 1))) ?></span><small><?= e($item['assignee_names'] ?: 'Sem responsáveis') ?></small></div><?php if ($item['due_date']): ?><div class="task-card-due <?= $item['due_date'] < date('Y-m-d') && $item['status'] !== 'done' ? 'is-overdue' : '' ?>"><span aria-hidden="true">◷</span><?= date('d/m/Y', strtotime($item['due_date'])) ?></div><?php endif; ?></a><?php if ($canManage): ?><a class="task-edit-link" href="task_form.php?id=<?= $item['id'] ?>">Editar tarefa <span aria-hidden="true">→</span></a><?php endif; ?></article>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($task || isset($_GET['new'])): ?>
    const modalElement = document.getElementById('taskEditorModal');
    if (modalElement && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalElement).show();
    <?php endif; ?>
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
