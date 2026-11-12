<?php
require_once __DIR__.'/includes/auth.php';
require_login();
ensure_v23_schema();
ensure_v17_schema();
require_once __DIR__.'/includes/objectives.php';
ensure_objectives_schema();
$uid=(int)current_user()['id'];
$taskId=max(0,(int)($_GET['task_id'] ?? 0));
$includeArchived=isset($_GET['archived']);
$params=[$uid];
$where='(t.is_private=0 OR t.creator_id=?)';
if (!$includeArchived) $where.=' AND t.archived_at IS NULL';
if ($taskId) { $where.=' AND t.id=?'; $params[]=$taskId; }
$q=db()->prepare('SELECT t.*,p.name project_name,'.task_assignee_names_sql('t').' assignee_names FROM tasks t JOIN projects p ON p.id=t.project_id WHERE '.$where.' ORDER BY p.name,t.title,t.id');
$q->execute($params); $tasks=$q->fetchAll();
if ($taskId && !$tasks) { http_response_code(404); exit('Tarefa não encontrada ou indisponível neste filtro.'); }
// Uma consulta por conjunto, sem limitar o relatório às tarefas recentes.
$q=db()->prepare('SELECT s.*,COUNT(o.id) objective_total,COALESCE(SUM(o.completed),0) objective_done FROM subtasks s JOIN tasks t ON t.id=s.task_id LEFT JOIN subtask_objectives o ON o.subtask_id=s.id WHERE '.$where.' GROUP BY s.id ORDER BY s.id');
$q->execute($params); $groups=[];
foreach ($q->fetchAll() as $s) $groups[$s['task_id']][]=$s;
$q=db()->prepare('SELECT d.subtask_id,d.description FROM subtask_details d JOIN subtasks s ON s.id=d.subtask_id JOIN tasks t ON t.id=s.task_id WHERE '.$where);
$q->execute($params); $subtaskDescriptions=$q->fetchAll(PDO::FETCH_KEY_PAIR);
$q=db()->prepare('SELECT o.* FROM subtask_objectives o JOIN subtasks s ON s.id=o.subtask_id JOIN tasks t ON t.id=s.task_id WHERE '.$where.' ORDER BY o.id');
$q->execute($params); $objectives=[];
foreach ($q->fetchAll() as $o) $objectives[$o['subtask_id']][]=$o;
$statuses=['todo'=>0,'doing'=>0,'review'=>0,'done'=>0];
$overdue=0; $totalGroups=0; $doneGroups=0; $totalObjectives=0; $doneObjectives=0; $measured=[];
foreach ($tasks as $t) {
    if (isset($statuses[$t['status']])) $statuses[$t['status']]++;
    if ($t['due_date'] && $t['due_date']<date('Y-m-d') && $t['status']!=='done') $overdue++;
    $ss=$groups[$t['id']] ?? [];
    if ($ss) $measured[]=objective_task_progress($ss);
    foreach ($ss as $s) {
        $totalGroups++; if (objective_ratio($s)>=1) $doneGroups++;
        $totalObjectives+=(int)$s['objective_total']; $doneObjectives+=(int)$s['objective_done'];
    }
}
$overall=$measured ? (int)round(array_sum($measured)/count($measured)) : 0;
$objectivePercent=$totalObjectives ? (int)round($doneObjectives/$totalObjectives*100) : 0;
$statusPercent=count($tasks) ? (int)round($statuses['done']/count($tasks)*100) : 0;
$pageTitle='Dashboard de resultados';
require __DIR__.'/includes/header.php';
?>
<link rel="stylesheet" href="assets/objectives.css">
<style>
.result-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:20px 0}.result-card{border:1px solid #94a3b8;border-radius:12px;padding:18px;display:flex;align-items:center;gap:14px}.result-card h2{font-size:17px;margin:0 0 8px}.result-status{display:flex;gap:12px;flex-wrap:wrap;margin:18px 0}.result-status span{border:1px solid #94a3b8;padding:10px;border-radius:8px}.result-task{margin:24px 0;padding:20px;border:1px solid #94a3b8;border-radius:12px;overflow-wrap:anywhere}.result-task-head{display:flex;justify-content:space-between;align-items:center;gap:16px}.result-task h2{margin:0}.result-meta{font-size:14px}.result-objectives{padding-left:22px}.result-objectives li{margin:7px 0}.result-subtask{padding:12px 0;border-top:1px solid #94a3b8}.result-note{font-size:13px}.report-filter{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.report-filter input{width:auto}.report-meter{height:12px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin:8px 0}.report-meter span{display:block;height:100%;background:#2563eb}.print-only{display:none}@media(max-width:900px){.result-summary{grid-template-columns:1fr}.result-card{padding:12px}}
@page{size:A4 portrait;margin:15mm}
@media print{html,body{background:white!important;color:#111827!important;font-size:10pt}body *{visibility:hidden}#results-report,#results-report *{visibility:visible}#results-report{position:absolute;left:0;top:0;width:100%;margin:0;padding:0;color:#111827;background:white}.no-print{display:none!important}.print-only{display:block}.result-summary{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.result-card{padding:8px;display:block;break-inside:avoid}.result-card h2{font-size:11pt}.result-card p{font-size:9pt}.objective-ring{width:85px;height:85px}.result-task{padding:12px;box-decoration-break:clone}.result-task-head,.result-subtask,.result-status{break-inside:avoid}.result-task-head{break-after:avoid}h1,h2,h3{break-after:avoid}.result-task h2{font-size:14pt}.result-task h3{font-size:11pt}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}a{color:#111827;text-decoration:none}}
</style>
<link rel="stylesheet" href="assets/results_layout.css?v=2">
<script>document.currentScript.closest('main.container')?.classList.add('results-dashboard-container');</script>
<div id="results-report">
<div class="page-head"><div><h1>Dashboard de resultados</h1><p>Emitido em <?= date('d/m/Y H:i') ?> · <?= count($tasks) ?> <?= count($tasks) === 1 ? 'tarefa' : 'tarefas' ?> <?= $includeArchived ? '(inclui arquivadas)' : 'não arquivadas' ?></p></div><div class="page-actions no-print"><a class="btn secondary" href="dashboard.php">Voltar</a><button class="btn" type="button" onclick="window.print()">Salvar dashboard em PDF</button></div></div>
<p class="no-print">Para gerar o PDF, clique no botão e selecione “Salvar como PDF” na janela de impressão. Os gráficos e os objetivos são incluídos no documento.</p>
<form method="get" class="report-filter no-print">
    <?php if ($taskId): ?><input type="hidden" name="task_id" value="<?= $taskId ?>"><?php endif; ?>
    <label><input type="checkbox" name="archived" value="1" <?= $includeArchived ? 'checked' : '' ?>> Incluir arquivadas</label><button class="btn secondary small">Aplicar</button>
    <?php if ($taskId): ?><a href="progress_dashboard.php">Ver todas as tarefas</a><?php endif; ?>
</form>
<div class="result-summary">
    <div class="result-card"><?= objective_ring($overall,'Execução das tarefas') ?><div><h2>Execução das tarefas</h2><p><?= count($measured) ?> <?= count($measured) === 1 ? 'tarefa com subtarefas' : 'tarefas com subtarefas' ?></p></div></div>
    <div class="result-card"><?= objective_ring($objectivePercent,'Objetivos concluídos') ?><div><h2>Objetivos concluídos</h2><p><?= $doneObjectives ?> / <?= $totalObjectives ?> objetivos</p></div></div>
    <div class="result-card"><?= objective_ring($statusPercent,'Tarefas com status concluído') ?><div><h2>Status concluído</h2><p><?= $statuses['done'] ?> / <?= count($tasks) ?> tarefas</p></div></div>
</div>
<p class="result-note">Execução: média do progresso das tarefas com subtarefas; cada tarefa usa a média de suas subtarefas. Cada subtarefa usa a proporção de objetivos concluídos; sem objetivos, vale sua marcação manual (0% ou 100%). Tarefas sem subtarefas ficam sem medição. Status e execução são indicadores independentes. Os percentuais são arredondados.</p>
<div class="result-status"><?php foreach ($statuses as $key=>$count): ?><span><?= e(task_status_label($key)) ?>: <strong><?= $count ?></strong></span><?php endforeach; ?><span>Atrasadas: <strong><?= $overdue ?></strong></span><span>Subtarefas concluídas: <strong><?= $doneGroups ?>/<?= $totalGroups ?></strong></span></div>
<p class="result-note">O relatório inclui apenas tarefas públicas e tarefas privadas criadas por você, seguindo as permissões atuais. Lembretes não fazem parte destes indicadores.</p>
<?php if (!$tasks): ?><p>Nenhuma tarefa disponível.</p><?php endif; ?>
<?php foreach ($tasks as $t): $ss=$groups[$t['id']] ?? []; $p=objective_task_progress($ss); ?>
<article class="result-task">
    <div class="result-task-head"><div><h2><?= e($t['title']) ?></h2><p><?= e($t['project_name']) ?> · <?= e(task_status_label($t['status'])) ?><?= $t['archived_at'] ? ' · Arquivada' : '' ?></p></div><?php if ($ss): ?><?= objective_ring($p,$t['title']) ?><?php endif; ?></div>
    <p class="result-meta">Responsáveis: <?= e($t['assignee_names'] ?: 'Não definidos') ?> · Prazo: <?= $t['due_date'] ? date('d/m/Y',strtotime($t['due_date'])) : 'Sem prazo' ?> · <?= e(priority_label($t['priority'])) ?></p>
    <?php if (!$ss): ?><p>Sem subtarefas — progresso não medido.</p><?php endif; ?>
    <?php foreach ($ss as $s): $sp=(int)round(objective_ratio($s)*100); ?>
    <section class="result-subtask"><h3><?= e($s['title']) ?> — <?= $sp ?>%</h3>
        <?php if (trim($subtaskDescriptions[$s['id']] ?? '') !== ''): ?><p><?= nl2br(e($subtaskDescriptions[$s['id']])) ?></p><?php endif; ?>
        <div class="report-meter" role="img" aria-label="Progresso <?= $sp ?> por cento"><span style="width:<?= $sp ?>%"></span></div>
        <?php if ((int)$s['objective_total']): ?><p><?= (int)$s['objective_done'] ?> / <?= (int)$s['objective_total'] ?> objetivos concluídos</p>
        <ul class="result-objectives"><?php foreach ($objectives[$s['id']] ?? [] as $o): ?><li><?= $o['completed'] ? '✓ Concluído' : '○ Pendente' ?> — <?= e($o['title']) ?></li><?php endforeach; ?></ul>
        <?php else: ?><p>Conclusão manual: <?= $s['completed'] ? 'concluída' : 'pendente' ?>.</p><?php endif; ?>
    </section>
    <?php endforeach; ?>
</article>
<?php endforeach; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
