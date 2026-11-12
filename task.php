<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v2_schema();
ensure_v8_schema();
ensure_v14_schema();
ensure_v17_schema();
ensure_v23_schema();
require_once __DIR__ . '/includes/objectives.php';
ensure_objectives_schema();
$pdo = db();
$u = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT t.*,p.name project_name,p.owner_id,' . task_assignee_names_sql('t') . ' assignee_names,c.name creator_name FROM tasks t JOIN projects p ON p.id=t.project_id JOIN users c ON c.id=t.creator_id WHERE t.id=? AND t.archived_at IS NULL AND (t.is_private=0 OR t.creator_id=?)');
$stmt->execute([$id, $u['id']]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('Tarefa não encontrada.'); }

$canManageTaskFiles = can_manage_task($task, (int)$u['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash_message('O envio excedeu o limite total do servidor. Envie menos arquivos por vez ou ajuste post_max_size no PHP.', 'error');
        redirect('task.php?id=' . $id);
    }
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['subtask','toggle_subtask','add_objective','toggle_objective','delete_objective','edit_objective','edit_subtask','delete_subtask','upload','delete_attachment'], true) && !$canManageTaskFiles) {
        http_response_code(403);
        exit('Somente o criador ou um responsável pode alterar subtarefas e anexos desta tarefa pública.');
    }

    if (in_array($action, ['add_objective','toggle_objective','delete_objective','edit_objective','edit_subtask','delete_subtask'], true)) {
        $sid = (int)($_POST['subtask_id'] ?? 0);
        // Bloqueio da subtarefa serializa inclusões e marcações simultâneas.
        $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $q=$pdo->prepare('SELECT id FROM subtasks WHERE id=? AND task_id=? FOR UPDATE');
            $q->execute([$sid,$id]);
            if (!$q->fetchColumn()) { $pdo->rollBack(); http_response_code(404); exit('Subtarefa não encontrada.'); }
            if ($action === 'edit_subtask' || $action === 'delete_subtask') {
                if ($action === 'delete_subtask') {
                    $q=$pdo->prepare('DELETE FROM subtasks WHERE id=? AND task_id=?');
                    $q->execute([$sid,$id]);
                    log_activity('excluiu uma subtarefa e seus objetivos', 'task', $id, $task['title']);
                } else {
                    $title=short_subtask_title((string)($_POST['title'] ?? ''));
                    $description=trim((string)($_POST['subtask_description'] ?? ''));
                    if ($title === '' || mb_strlen($title)>60 || mb_strlen($description)>300) {
                        flash_message('Informe um nome de até 60 caracteres e uma descrição de até 300 caracteres.', 'error');
                    } elseif (subtask_title_exists($id,$title,$sid)) {
                        flash_message('Já existe uma subtarefa com esse nome nesta tarefa.', 'error');
                    } else {
                        $q=$pdo->prepare('UPDATE subtasks SET title=? WHERE id=? AND task_id=?');
                        $q->execute([$title,$sid,$id]);
                        save_subtask_description($sid,$description);
                        log_activity('editou o nome de uma subtarefa', 'task', $id, $title);
                    }
                }
            } elseif ($action === 'add_objective') {
                $title=trim((string)($_POST['objective_title'] ?? ''));
                if ($title === '' || mb_strlen($title) > 220) {
                    flash_message('Informe um objetivo com até 220 caracteres.', 'error');
                } else {
                    $q=$pdo->prepare('INSERT INTO subtask_objectives(subtask_id,title) VALUES(?,?)');
                    $q->execute([$sid,$title]);
                    log_activity('adicionou objetivo à subtarefa', 'task', $id, $title);
                }
            } else {
                $oid=(int)($_POST['objective_id'] ?? 0);
                if ($action === 'edit_objective') {
                    $title=trim((string)($_POST['objective_title'] ?? ''));
                    if ($title === '' || mb_strlen($title)>220) {
                        flash_message('Informe um objetivo com até 220 caracteres.', 'error');
                    } else {
                        $q=$pdo->prepare('UPDATE subtask_objectives SET title=? WHERE id=? AND subtask_id=?');
                        $q->execute([$title,$oid,$sid]);
                    }
                } elseif ($action === 'delete_objective') {
                    $q=$pdo->prepare('DELETE FROM subtask_objectives WHERE id=? AND subtask_id=?');
                    $q->execute([$oid,$sid]);
                } else {
                    $q=$pdo->prepare('UPDATE subtask_objectives SET completed=? WHERE id=? AND subtask_id=?');
                    $q->execute([empty($_POST['completed']) ? 0 : 1,$oid,$sid]);
                }
                if ($q->rowCount()) log_activity('atualizou objetivos da subtarefa', 'task', $id, $task['title']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_message('Não foi possível salvar o objetivo. Tente novamente.', 'error');
        }
        redirect('task.php?id='.$id.($action === 'delete_subtask' ? '#subtasks-list' : '#subtask-'.$sid));
    }

    if ($action === 'comment') {
        $body = trim($_POST['body'] ?? '');
        $files = $_FILES['comment_images'] ?? null;
        $hasUpload = false;
        if ($files && is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $hasUpload = true; break; }
            }
        }

        if ($body !== '' || $hasUpload) {
            $savedUploads = [];
            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare('INSERT INTO comments(task_id,user_id,body) VALUES(?,?,?)');
                $s->execute([$id, $u['id'], $body]);
                $commentId = (int)$pdo->lastInsertId();

                if ($files && is_array($files['name'] ?? null)) {
                    foreach ($files['name'] as $i => $originalName) {
                        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                        $stored = save_task_upload([
                            'name' => $originalName,
                            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $files['size'][$i] ?? 0,
                            'tmp_name' => $files['tmp_name'][$i] ?? '',
                        ]);
                        $savedUploads[] = $stored;
                        $s = $pdo->prepare('INSERT INTO comment_images(comment_id,user_id,original_name,stored_name) VALUES(?,?,?,?)');
                        $s->execute([$commentId, $u['id'], $originalName, $stored]);
                    }
                }
                log_activity('comentou na tarefa', 'task', $id, $task['title']);
                $pdo->commit();
                $_SESSION['flash'] = 'Comentário adicionado com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($savedUploads as $storedName) remove_uploaded_file($storedName);
                flash_message($e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar o comentário e seus arquivos.', 'error');
            }
        }
    } elseif ($action === 'edit_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');

        $s = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM comment_images ci WHERE ci.comment_id=c.id) image_count FROM comments c WHERE c.id=? AND c.task_id=?');
        $s->execute([$commentId, $id]);
        $comment = $s->fetch();

        if (!$comment) {
            flash_message('Comentário não encontrado.', 'error');
        } elseif ((int)$comment['user_id'] !== (int)$u['id']) {
            http_response_code(403);
            exit('Você não possui permissão para editar este comentário.');
        } elseif ($body === '' && (int)$comment['image_count'] === 0) {
            flash_message('O comentário precisa ter texto ou pelo menos um arquivo.', 'error');
        } else {
            $s = $pdo->prepare('UPDATE comments SET body=? WHERE id=? AND task_id=?');
            $s->execute([$body, $commentId, $id]);
            log_activity('editou um comentário', 'task', $id, $task['title']);
            $_SESSION['flash'] = 'Comentário atualizado com sucesso.';
        }
    } elseif ($action === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $s = $pdo->prepare('SELECT * FROM comments WHERE id=? AND task_id=?');
        $s->execute([$commentId, $id]);
        $comment = $s->fetch();

        if (!$comment) {
            flash_message('Comentário não encontrado.', 'error');
        } elseif ((int)$comment['user_id'] !== (int)$u['id']) {
            http_response_code(403);
            exit('Você não possui permissão para excluir este comentário.');
        } else {
            $s = $pdo->prepare('SELECT stored_name FROM comment_images WHERE comment_id=?');
            $s->execute([$commentId]);
            $filesToDelete = array_column($s->fetchAll(), 'stored_name');

            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare('DELETE FROM comments WHERE id=? AND task_id=?');
                $s->execute([$commentId, $id]);
                log_activity('excluiu um comentário', 'task', $id, $task['title']);
                $pdo->commit();
                foreach ($filesToDelete as $storedName) remove_uploaded_file($storedName);
                $_SESSION['flash'] = 'Comentário excluído com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } elseif ($action === 'delete_comment_image') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $s = $pdo->prepare('SELECT ci.*, c.user_id comment_user_id, c.body, c.id comment_id, (SELECT COUNT(*) FROM comment_images ci2 WHERE ci2.comment_id=c.id) image_count FROM comment_images ci JOIN comments c ON c.id=ci.comment_id WHERE ci.id=? AND c.task_id=?');
        $s->execute([$imageId, $id]);
        $img = $s->fetch();

        if (!$img) {
            flash_message('Arquivo não encontrado.', 'error');
        } elseif ((int)$img['comment_user_id'] !== (int)$u['id']) {
            http_response_code(403);
            exit('Você não possui permissão para excluir este arquivo.');
        } else {
            $deleteWholeComment = trim((string)$img['body']) === '' && (int)$img['image_count'] <= 1;
            $pdo->beginTransaction();
            try {
                if ($deleteWholeComment) {
                    $s = $pdo->prepare('DELETE FROM comments WHERE id=? AND task_id=?');
                    $s->execute([(int)$img['comment_id'], $id]);
                    log_activity('excluiu um comentário', 'task', $id, $task['title']);
                    $_SESSION['flash'] = 'O arquivo era o único conteúdo do comentário; o comentário também foi excluído.';
                } else {
                    $s = $pdo->prepare('DELETE FROM comment_images WHERE id=?');
                    $s->execute([$imageId]);
                    log_activity('removeu um arquivo de comentário', 'task', $id, $img['original_name']);
                    $_SESSION['flash'] = 'Arquivo removido do comentário.';
                }
                $pdo->commit();
                remove_uploaded_file($img['stored_name']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } elseif ($action === 'subtask') {
        $title=short_subtask_title((string)($_POST['title'] ?? ''));
        $description=trim((string)($_POST['subtask_description'] ?? ''));
        if ($title === '' || mb_strlen($title)>60 || mb_strlen($description)>300) {
            flash_message('Informe um nome de até 60 caracteres e uma descrição de até 300 caracteres.', 'error');
        } else {
            $pdo->beginTransaction();
            try {
                $lock=$pdo->prepare('SELECT id FROM tasks WHERE id=? FOR UPDATE');
                $lock->execute([$id]);
                if (subtask_title_exists($id,$title)) {
                    $pdo->rollBack();
                    flash_message('Já existe uma subtarefa com esse nome nesta tarefa.', 'error');
                    redirect('task.php?id='.$id.'#subtasks-list');
                }
                $s=$pdo->prepare('INSERT INTO subtasks(task_id,title) VALUES(?,?)');
                $s->execute([$id,$title]);
                $newSubtaskId=(int)$pdo->lastInsertId();
                save_subtask_description($newSubtaskId,$description);
                log_activity('adicionou uma subtarefa', 'task', $id, $title);
                $pdo->commit();
                redirect('task.php?id='.$id.'#subtask-'.$newSubtaskId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash_message('Não foi possível criar a subtarefa. Tente novamente.', 'error');
            }
        }
    } elseif ($action === 'toggle_subtask') {
        $sid = (int)($_POST['subtask_id'] ?? 0);
        $s = $pdo->prepare('UPDATE subtasks SET completed=1-completed WHERE id=? AND task_id=? AND NOT EXISTS (SELECT 1 FROM subtask_objectives o WHERE o.subtask_id=subtasks.id)');
        $s->execute([$sid, $id]);
        log_activity('atualizou uma subtarefa', 'task', $id, $task['title']);
        $_SESSION['flash'] = 'Subtarefa atualizada.';
    } elseif ($action === 'upload') {
        $stored = null;
        try {
            $stored = save_task_upload($_FILES['file'] ?? []);
            $pdo->beginTransaction();
            $s = $pdo->prepare('INSERT INTO attachments(task_id,user_id,original_name,stored_name) VALUES(?,?,?,?)');
            $s->execute([$id, $u['id'], $_FILES['file']['name'], $stored]);
            log_activity('anexou um arquivo', 'task', $id, $_FILES['file']['name']);
            $pdo->commit();
            flash_message('Arquivo anexado com sucesso.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored) remove_uploaded_file($stored);
            flash_message($e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar o anexo.', 'error');
        }
    } elseif ($action === 'delete_attachment') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $s = $pdo->prepare('SELECT * FROM attachments WHERE id=? AND task_id=?');
        $s->execute([$attachmentId, $id]);
        $attachment = $s->fetch();

        if (!$attachment) {
            flash_message('Anexo não encontrado.', 'error');
        } else {
            $canDeleteAttachment = $canManageTaskFiles || (int)$attachment['user_id'] === (int)$u['id'];
            if (!$canDeleteAttachment) {
                http_response_code(403);
                exit('Você não possui permissão para excluir este anexo.');
            }
            $s = $pdo->prepare('DELETE FROM attachments WHERE id=? AND task_id=?');
            $s->execute([$attachmentId, $id]);
            remove_uploaded_file($attachment['stored_name']);
            log_activity('removeu um anexo', 'task', $id, $attachment['original_name']);
            $_SESSION['flash'] = 'Anexo excluído com sucesso.';
        }
    }
    redirect('task.php?id=' . $id);
}

$subtasks = objective_subtasks($id);
$objectivesBySubtask = [];
$s=$pdo->prepare('SELECT o.* FROM subtask_objectives o JOIN subtasks s ON s.id=o.subtask_id WHERE s.task_id=? ORDER BY o.id');
$s->execute([$id]);
foreach ($s->fetchAll() as $objective) $objectivesBySubtask[$objective['subtask_id']][]=$objective;

$s = $pdo->prepare('SELECT c.*,u.name user_name FROM comments c JOIN users u ON u.id=c.user_id WHERE task_id=? ORDER BY c.created_at DESC');
$s->execute([$id]);
$comments = $s->fetchAll();

$commentImages = [];
$s = $pdo->prepare('SELECT ci.* FROM comment_images ci JOIN comments c ON c.id=ci.comment_id WHERE c.task_id=? ORDER BY ci.created_at');
$s->execute([$id]);
foreach ($s->fetchAll() as $img) $commentImages[$img['comment_id']][] = $img;

$s = $pdo->prepare('SELECT a.*,u.name user_name FROM attachments a JOIN users u ON u.id=a.user_id WHERE task_id=? ORDER BY a.created_at DESC');
$s->execute([$id]);
$attachments = $s->fetchAll();
$imageAttachments = [];
$otherAttachments = [];
foreach ($attachments as $a) {
    if (is_image_name($a['original_name'])) $imageAttachments[] = $a;
    else $otherAttachments[] = $a;
}

$doneCount = 0;
foreach ($subtasks as $st) if (objective_ratio($st) >= 1) $doneCount++;
$progress = objective_task_progress($subtasks);
$canDeleteTask = $canManageTaskFiles;
$pageTitle = $task['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-head task-detail-head">
    <div class="task-detail-title">
        <a class="back" href="task_form.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Voltar para o quadro</a>
        <span class="task-page-eyebrow">Detalhes da tarefa</span>
        <h1><?= e($task['title']) ?></h1>
        <p class="muted">Criada por <?= e($task['creator_name']) ?> <span>•</span> <i class="bi <?= !empty($task['is_private']) ? 'bi-lock-fill' : 'bi-people-fill' ?>" aria-hidden="true"></i> <?= !empty($task['is_private']) ? 'Privada' : 'Pública' ?></p>
    </div>
    <div class="page-actions task-detail-actions">
        <a class="btn secondary" href="progress_dashboard.php?task_id=<?= $id ?>">Dashboard e PDF</a>
        <?php if ($canDeleteTask): ?><a class="btn secondary" href="task_form.php?id=<?= $task['id'] ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i> Editar</a><?php endif; ?>
        <?php if ($canDeleteTask && $task['status'] === 'done'): ?>
        <form method="post" action="archive_task.php" onsubmit="return confirm('Arquivar esta tarefa concluída?');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <button class="btn secondary" type="submit"><i class="bi bi-archive-fill" aria-hidden="true"></i> Arquivar</button>
        </form>
        <?php endif; ?>
        <?php if ($canDeleteTask): ?>
        <form method="post" action="delete_task.php" onsubmit="return confirm('Tem certeza que deseja apagar esta tarefa? Comentários, subtarefas e anexos também serão apagados.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $task['id'] ?>">
            <button class="btn danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Excluir</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="two-col task-layout task-detail-workspace">
<section>
    <div class="panel task-overview-card status-<?= e($task['status']) ?>">
        <div class="task-section-heading task-overview-heading">
            <div><span class="task-section-icon">◎</span><div><h2>Visão geral</h2><p>Informações principais e planejamento</p></div></div>
            <span class="badge <?= e($task['status']) ?>"><?= e(task_status_label($task['status'])) ?></span>
        </div>
        <div class="task-detail-grid">
            <div><span class="task-metric-icon">↻</span><small>Status</small><strong><?= e(task_status_label($task['status'])) ?></strong></div>
            <div><span class="task-metric-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><small>Prioridade</small><strong><?= e(priority_label($task['priority'])) ?></strong></div>
            <div><span class="task-metric-icon">♙</span><small>Responsáveis</small><strong><?= e($task['assignee_names'] ?: 'Não definidos') ?></strong></div>
            <div><span class="task-metric-icon">□</span><small>Prazo</small><strong><?= $task['due_date'] ? date('d/m/Y', strtotime($task['due_date'])) : 'Sem prazo' ?></strong></div>
        </div>
        <div class="task-description-block"><h3>Descrição</h3><p class="description"><?= nl2br(e($task['description'] ?: 'Sem descrição.')) ?></p></div>
    </div>

    <?php if ($imageAttachments): ?>
    <div class="panel task-section-card">
        <div class="panel-head task-section-heading"><div><span class="task-section-icon">▧</span><div><h2>Imagens da tarefa</h2><p>Galeria de referências visuais</p></div></div><span class="task-count-pill"><?= count($imageAttachments) ?></span></div>
        <div class="image-gallery">
            <?php foreach ($imageAttachments as $a): ?>
            <?php $canDeleteThisAttachment = $canManageTaskFiles || (int)$a['user_id'] === (int)$u['id']; ?>
            <div class="image-card">
                <button type="button" class="image-thumb js-image-preview" data-src="download.php?kind=attachment&amp;id=<?= (int)$a['id'] ?>" data-title="<?= e($a['original_name']) ?>">
                    <img src="download.php?kind=attachment&amp;id=<?= (int)$a['id'] ?>" alt="<?= e($a['original_name']) ?>">
                    <span><?= e($a['original_name']) ?></span>
                </button>
                <?php if ($canDeleteThisAttachment): ?>
                <form method="post" class="image-delete-form" onsubmit="return confirm('Excluir esta imagem anexada?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_attachment">
                    <input type="hidden" name="attachment_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn danger small">Excluir imagem</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/objectives_panel.php'; ?>

    <div class="panel task-section-card task-comments-card">
        <div class="task-section-heading"><div><span class="task-section-icon">◌</span><div><h2>Comentários</h2><p>Registre atualizações e informações importantes</p></div></div><span class="task-count-pill"><?= count($comments) ?></span></div>
        <form method="post" enctype="multipart/form-data" class="comment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="comment">
            <textarea name="body" rows="3" placeholder="Escreva um comentário..."></textarea>
            <label>Adicionar arquivos ao comentário</label>
            <input type="file" name="comment_images[]" multiple>
            <div class="comment-form-footer"><p class="muted tiny">Envie imagens, PDF, CSV ou outros documentos. Até 10 MB por arquivo.</p><button class="btn small">Publicar comentário</button></div>
        </form>
        <div class="comments">
            <?php if (!$comments): ?><div class="task-inline-empty"><span>◌</span><p>Ainda não há comentários nesta tarefa.</p></div><?php endif; ?>
            <?php foreach ($comments as $c): ?>
            <?php $canManageComment = (int)$c['user_id'] === (int)$u['id']; ?>
            <article class="comment-card" id="comment-<?= $c['id'] ?>">
                <div class="comment-head">
                    <div><strong><?= e($c['user_name']) ?></strong><small><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></small></div>
                    <?php if ($canManageComment): ?>
                    <div class="comment-actions">
                        <button type="button" class="comment-action js-edit-comment" data-comment-id="<?= $c['id'] ?>">Editar</button>
                        <form method="post" onsubmit="return confirm('Excluir este comentário? Os arquivos dele também serão removidas.');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_comment">
                            <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="comment-action danger-text">Excluir</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="comment-display" data-comment-display="<?= $c['id'] ?>">
                    <?php if (trim($c['body']) !== ''): ?><p><?= nl2br(e($c['body'])) ?></p><?php endif; ?>
                </div>

                <?php if ($canManageComment): ?>
                <form method="post" class="comment-edit-form" data-comment-edit="<?= $c['id'] ?>" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="edit_comment">
                    <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                    <textarea name="body" rows="3"><?= e($c['body']) ?></textarea>
                    <div class="comment-edit-buttons">
                        <button class="btn small" type="submit">Salvar</button>
                        <button class="btn secondary small js-cancel-edit" type="button" data-comment-id="<?= $c['id'] ?>">Cancelar</button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (!empty($commentImages[$c['id']])): ?>
                <div class="comment-images">
                    <?php foreach ($commentImages[$c['id']] as $img): ?>
                    <div class="comment-image-wrap">
                        <?php if (is_image_name($img['original_name'])): ?>
                        <button type="button" class="comment-image js-image-preview" data-src="download.php?kind=comment&amp;id=<?= (int)$img['id'] ?>" data-title="<?= e($img['original_name']) ?>">
                            <img src="download.php?kind=comment&amp;id=<?= (int)$img['id'] ?>" alt="<?= e($img['original_name']) ?>">
                        </button>
                        <?php else: ?>
                        <a class="btn secondary small" href="download.php?kind=comment&amp;id=<?= (int)$img['id'] ?>">Baixar <?= e($img['original_name']) ?></a>
                        <?php endif; ?>
                        <?php if ($canManageComment): ?>
                        <form method="post" class="comment-image-delete" onsubmit="return confirm('Excluir este arquivo do comentário?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_comment_image">
                            <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                            <button type="submit" title="Excluir arquivo" aria-label="Excluir arquivo"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<aside class="task-detail-sidebar">
    <div class="panel task-attachments-card">
        <div class="task-section-heading"><div><span class="task-section-icon">⌕</span><div><h2>Anexos</h2><p>Arquivos relacionados à tarefa</p></div></div><span class="task-count-pill"><?= count($attachments) ?></span></div>
        <?php if ($canManageTaskFiles): ?><form method="post" enctype="multipart/form-data" class="task-upload-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload"><input type="file" name="file" required>
            <button class="btn small"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Enviar arquivo</button>
        </form>
        <p class="muted tiny">Até 10 MB por arquivo: imagens, PDF, CSV e outros documentos.</p>
        <?php endif; ?>

        <?php if ($imageAttachments): ?>
        <h3 class="attachment-subtitle">Imagens</h3>
        <div class="attachment-mini-grid">
            <?php foreach ($imageAttachments as $a): ?>
            <button type="button" class="attachment-mini js-image-preview" data-src="download.php?kind=attachment&amp;id=<?= (int)$a['id'] ?>" data-title="<?= e($a['original_name']) ?>">
                <img src="download.php?kind=attachment&amp;id=<?= (int)$a['id'] ?>" alt="<?= e($a['original_name']) ?>">
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($otherAttachments): ?>
        <h3 class="attachment-subtitle">Outros arquivos</h3>
        <div class="attachments">
            <?php foreach ($otherAttachments as $a): ?>
            <a href="download.php?kind=attachment&amp;id=<?= (int)$a['id'] ?>" target="_blank"><strong><?= e($a['original_name']) ?></strong><small><?= e($a['user_name']) ?> • <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small></a>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$imageAttachments): ?>
            <p class="empty">Nenhum anexo enviado.</p>
        <?php endif; ?>
    </div>
</aside>
</div>

<div class="image-modal" id="imageModal" aria-hidden="true">
    <button type="button" class="image-modal-close" aria-label="Fechar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    <div class="image-modal-content">
        <img src="" alt="Imagem ampliada" id="imageModalImg">
        <div class="image-modal-title" id="imageModalTitle"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
