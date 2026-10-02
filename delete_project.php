<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v2_schema();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
verify_csrf();

$pdo = db();
$user = current_user();
$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) { http_response_code(404); exit('Projeto não encontrado.'); }

$canDelete = ($user['role'] ?? '') === 'admin' || (int)$project['owner_id'] === (int)$user['id'];
if (!$canDelete) { http_response_code(403); exit('Você não possui permissão para remover este projeto.'); }

$files = [];
$s = $pdo->prepare('SELECT a.stored_name FROM attachments a JOIN tasks t ON t.id=a.task_id WHERE t.project_id=?');
$s->execute([$id]);
$files = array_merge($files, array_column($s->fetchAll(), 'stored_name'));
$s = $pdo->prepare('SELECT ci.stored_name FROM comment_images ci JOIN comments c ON c.id=ci.comment_id JOIN tasks t ON t.id=c.task_id WHERE t.project_id=?');
$s->execute([$id]);
$files = array_merge($files, array_column($s->fetchAll(), 'stored_name'));

$pdo->beginTransaction();
try {
    log_activity('removeu o projeto', 'project', $id, $project['name']);
    $s = $pdo->prepare('DELETE FROM projects WHERE id=?');
    $s->execute([$id]);
    $pdo->commit();
    foreach ($files as $file) remove_uploaded_file($file);
    $_SESSION['flash'] = 'Projeto removido com sucesso.';
    redirect('projects.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
