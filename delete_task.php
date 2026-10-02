<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v2_schema();
ensure_v8_schema();
ensure_v17_schema();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
verify_csrf();

$pdo = db();
$user = current_user();
$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT t.*, p.owner_id, p.name project_name FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=?');
$stmt->execute([$id]);
$task = $stmt->fetch();
if (!$task) { flash_message('Não foi possível excluir: tarefa não encontrada.', 'error'); redirect('task_form.php'); }

$canDelete = can_manage_task($task, (int)$user['id']);
if (!$canDelete) { flash_message('Não foi possível excluir: você não possui permissão.', 'error'); redirect('task_form.php'); }

$files = [];
$s = $pdo->prepare('SELECT stored_name FROM attachments WHERE task_id=?');
$s->execute([$id]);
$files = array_merge($files, array_column($s->fetchAll(), 'stored_name'));
$s = $pdo->prepare('SELECT ci.stored_name FROM comment_images ci JOIN comments c ON c.id=ci.comment_id WHERE c.task_id=?');
$s->execute([$id]);
$files = array_merge($files, array_column($s->fetchAll(), 'stored_name'));

$pdo->beginTransaction();
try {
    log_activity('apagou a tarefa', 'task', $id, $task['title']);
    $s = $pdo->prepare('DELETE FROM tasks WHERE id=?');
    $s->execute([$id]);
    $pdo->commit();
    foreach ($files as $file) remove_uploaded_file($file);
    flash_message('Tarefa excluída com sucesso.', 'success');
    redirect('task_form.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_message('Não foi possível excluir a tarefa. Tente novamente.', 'error');
    redirect('task.php?id=' . $id);
}
