<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v23_schema();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
verify_csrf();

$pdo = db();
$user = current_user();
$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id,title,status,creator_id,is_private FROM tasks WHERE id=? AND archived_at IS NULL');
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) { flash_message('Não foi possível arquivar: tarefa não encontrada.', 'error'); redirect('task_form.php'); }
if (!can_manage_task($task, (int)$user['id'])) { flash_message('Você não possui permissão para arquivar esta tarefa.', 'error'); redirect('task_form.php'); }
if ($task['status'] !== 'done') { flash_message('Somente tarefas concluídas podem ser arquivadas.', 'warning'); redirect('task_form.php'); }

$stmt = $pdo->prepare('UPDATE tasks SET archived_at=NOW(),archived_by=? WHERE id=? AND archived_at IS NULL');
$stmt->execute([(int)$user['id'], $id]);
log_activity('arquivou a tarefa', 'task', $id, $task['title']);
flash_message('Tarefa arquivada. Ela continua armazenada e pode ser restaurada.', 'success');
redirect('task_form.php');
