<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v14_schema();
redirect('task_form.php');
$pdo = db();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*,u.name owner_name FROM projects p JOIN users u ON u.id=p.owner_id WHERE p.id=?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Projeto não encontrado.'); }

$stmt = $pdo->prepare('SELECT t.*,' . task_assignee_names_sql('t') . ' assignee_names FROM tasks t WHERE t.project_id=? AND t.archived_at IS NULL ORDER BY FIELD(t.priority,"urgent","high","medium","low"),t.due_date IS NULL,t.due_date');
$stmt->execute([$id]);
$tasks = $stmt->fetchAll();
$groups = ['todo'=>[], 'doing'=>[], 'review'=>[], 'done'=>[]];
foreach ($tasks as $t) $groups[$t['status']][] = $t;
$canDeleteProject = ($user['role'] ?? '') === 'admin' || (int)$project['owner_id'] === (int)$user['id'];
$pageTitle = $project['name'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div><a class="back" href="projects.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Projetos</a><h1><?= e($project['name']) ?></h1><p class="muted"><?= e($project['description'] ?: 'Sem descrição') ?> • Responsável: <?= e($project['owner_name']) ?></p></div>
    <div class="page-actions">
        <a class="btn" href="task_form.php?project_id=<?= $project['id'] ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova tarefa</a>
        <?php if ($canDeleteProject): ?>
        <form method="post" action="delete_project.php" onsubmit="return confirm('Tem certeza que deseja remover este projeto? Todas as tarefas, comentários e anexos também serão apagados.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $project['id'] ?>">
            <button class="btn danger" type="submit">Remover projeto</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<div class="kanban" data-project="<?= $project['id'] ?>">
<?php foreach (['todo'=>'A Fazer','doing'=>'Em andamento','review'=>'Em revisão','done'=>'Concluído'] as $status=>$label): ?>
<section class="kanban-col"><div class="kanban-head"><h2><?= $label ?></h2><span><?= count($groups[$status]) ?></span></div><div class="dropzone" data-status="<?= $status ?>">
<?php foreach ($groups[$status] as $t): ?>
<article class="task-card" draggable="true" data-task-id="<?= $t['id'] ?>"><a href="task.php?id=<?= $t['id'] ?>"><div class="task-meta"><span class="priority <?= e($t['priority']) ?>"><?= e(priority_label($t['priority'])) ?></span><?php if ($t['due_date']): ?><span><?= date('d/m', strtotime($t['due_date'])) ?></span><?php endif; ?></div><h3><?= e($t['title']) ?></h3><small><?= e($t['assignee_names'] ?: 'Sem responsáveis') ?></small></a></article>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
