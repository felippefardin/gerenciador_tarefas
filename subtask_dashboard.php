<?php
require_once __DIR__.'/includes/auth.php';
require_login();
ensure_v23_schema();
ensure_v17_schema();
require_once __DIR__.'/includes/objectives.php';
ensure_objectives_schema();
$sid=(int)($_GET['id'] ?? 0);
$uid=(int)current_user()['id'];
// Aplica a mesma visibilidade da página da tarefa, antes de ler seus objetivos.
$q=db()->prepare('SELECT s.*,d.description subtask_description,t.title task_title,t.id parent_task_id,t.status task_status,t.priority,t.due_date,t.archived_at,p.name project_name,c.name creator_name,'.task_assignee_names_sql('t').' assignee_names
    FROM subtasks s JOIN tasks t ON t.id=s.task_id JOIN projects p ON p.id=t.project_id JOIN users c ON c.id=t.creator_id
    LEFT JOIN subtask_details d ON d.subtask_id=s.id
    WHERE s.id=? AND (t.is_private=0 OR t.creator_id=?)');
$q->execute([$sid,$uid]); $subtask=$q->fetch();
if (!$subtask) { http_response_code(404); exit('Subtarefa não encontrada.'); }
$q=db()->prepare('SELECT id,title,completed FROM subtask_objectives WHERE subtask_id=? ORDER BY id');
$q->execute([$sid]); $objectives=$q->fetchAll();
$total=count($objectives);
$done=0;
foreach ($objectives as $objective) if ((int)$objective['completed']) $done++;
$pending=$total-$done;
$subtask['objective_total']=$total;
$subtask['objective_done']=$done;
$percent=(int)round(objective_ratio($subtask)*100);
$description=trim((string)($subtask['subtask_description'] ?? ''));
$reportTitle='Dashboard da subtarefa — '.$subtask['title'];
$backUrl=$subtask['archived_at'] ? 'progress_dashboard.php?task_id='.(int)$subtask['parent_task_id'].'&archived=1' : 'task.php?id='.(int)$subtask['parent_task_id'].'#subtask-'.$sid;
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($reportTitle) ?></title><link rel="stylesheet" href="assets/subtask_report.css"></head>
<body>
<main class="subtask-report">
    <nav class="report-actions no-print" aria-label="Ações do relatório"><a href="<?= e($backUrl) ?>">Voltar para a tarefa</a><button type="button" onclick="window.print()">Salvar relatório completo em PDF</button></nav>
    <p class="report-help no-print">Clique em salvar e escolha “Salvar como PDF” na janela de impressão. O documento inclui as informações, os gráficos e todos os objetivos desta subtarefa.</p>
    <header class="report-heading"><p class="eyebrow">Dashboard da subtarefa</p><h1><?= e($subtask['title']) ?></h1><p>Emitido em <?= date('d/m/Y H:i') ?></p></header>
    <section class="report-context" aria-label="Informações da tarefa principal">
        <dl><div><dt>Tarefa principal</dt><dd><?= e($subtask['task_title']) ?></dd></div><div><dt>Projeto</dt><dd><?= e($subtask['project_name']) ?></dd></div><div><dt>Responsáveis pela tarefa</dt><dd><?= e($subtask['assignee_names'] ?: 'Não definidos') ?></dd></div><div><dt>Prazo da tarefa</dt><dd><?= $subtask['due_date'] ? date('d/m/Y',strtotime($subtask['due_date'])) : 'Sem prazo' ?></dd></div><div><dt>Status da tarefa</dt><dd><?= e(task_status_label($subtask['task_status'])) ?><?= $subtask['archived_at'] ? ' (arquivada)' : '' ?></dd></div><div><dt>Prioridade da tarefa</dt><dd><?= e(priority_label($subtask['priority'])) ?></dd></div></dl>
    </section>
    <section class="report-description"><h2>Descrição da subtarefa</h2><p><?= $description !== '' ? nl2br(e($description)) : 'Sem descrição.' ?></p></section>
    <section class="report-metrics" aria-label="Resultados desta subtarefa">
        <div class="report-progress"><?= objective_ring($percent,'Progresso desta subtarefa') ?><div><h2>Progresso da subtarefa</h2><strong><?= $percent ?>%</strong><p><?= $total ? $done.' de '.$total.' objetivos concluídos' : 'Conclusão manual: '.($subtask['completed'] ? 'concluída' : 'pendente') ?></p></div></div>
        <div class="metric"><span>Total de objetivos</span><strong><?= $total ?></strong></div>
        <div class="metric"><span>Concluídos</span><strong><?= $done ?></strong></div>
        <div class="metric"><span>Pendentes</span><strong><?= $pending ?></strong></div>
    </section>
    <div class="progress-bar" role="img" aria-label="Barra de progresso: <?= $percent ?> por cento"><svg viewBox="0 0 1000 18" preserveAspectRatio="none" aria-hidden="true"><rect width="1000" height="18" rx="9" fill="#e2e8f0"/><rect width="<?= $percent*10 ?>" height="18" rx="9" fill="#2563eb"/></svg><span><?= $percent ?>% concluído</span></div>
    <p class="report-note"><?= $total ? 'Percentual = objetivos concluídos ÷ total de objetivos × 100, arredondado. Cada objetivo tem o mesmo peso.' : 'Esta subtarefa ainda não tem objetivos. O percentual segue sua marcação manual.' ?> O status da tarefa principal é independente deste percentual.</p>
    <section class="report-objectives"><h2>Objetivos da subtarefa</h2>
    <?php if (!$objectives): ?><p>Nenhum objetivo cadastrado.</p><?php else: ?>
    <table><thead><tr><th scope="col">Nº</th><th scope="col">Objetivo</th><th scope="col">Situação</th></tr></thead><tbody>
        <?php foreach ($objectives as $i=>$objective): ?><tr><td><?= $i+1 ?></td><td><?= e($objective['title']) ?></td><td><span class="objective-state <?= $objective['completed'] ? 'state-done' : 'state-pending' ?>"><?= $objective['completed'] ? '✓ Concluído' : '○ Pendente' ?></span></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?></section>
    <footer class="report-footer">Relatório da subtarefa #<?= $sid ?> · Tarefa #<?= (int)$subtask['parent_task_id'] ?> · Resultados no momento da emissão.</footer>
</main>
</body></html>
