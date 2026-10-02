<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v2_schema();
ensure_v8_schema();
ensure_v14_schema();
ensure_v17_schema();
$pdo = db();
$u = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT t.*,p.name project_name,p.owner_id,' . task_assignee_names_sql('t') . ' assignee_names,c.name creator_name FROM tasks t JOIN projects p ON p.id=t.project_id JOIN users c ON c.id=t.creator_id WHERE t.id=? AND (t.is_private=0 OR t.creator_id=?)');
$stmt->execute([$id, $u['id']]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('Tarefa não encontrada.'); }

$canManageTaskFiles = can_manage_task($task, (int)$u['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['subtask','toggle_subtask','upload','delete_attachment'], true) && !$canManageTaskFiles) {
        http_response_code(403);
        exit('Somente o criador ou um responsável pode alterar subtarefas e anexos desta tarefa pública.');
    }

    if ($action === 'comment') {
        $body = trim($_POST['body'] ?? '');
        $files = $_FILES['comment_images'] ?? null;
        $hasUpload = false;
        if ($files && is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $name !== '') { $hasUpload = true; break; }
            }
        }

        if ($body !== '' || $hasUpload) {
            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare('INSERT INTO comments(task_id,user_id,body) VALUES(?,?,?)');
                $s->execute([$id, $u['id'], $body]);
                $commentId = (int)$pdo->lastInsertId();

                if ($files && is_array($files['name'] ?? null)) {
                    foreach ($files['name'] as $i => $originalName) {
                        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($error !== UPLOAD_ERR_OK || $originalName === '') continue;
                        $size = (int)($files['size'][$i] ?? 0);
                        if ($size <= 0 || $size > 10 * 1024 * 1024) continue;
                        $tmp = $files['tmp_name'][$i] ?? '';
                        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) continue;
                        if (!@getimagesize($tmp)) continue;

                        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
                        $dest = __DIR__ . '/uploads/' . $stored;
                        if (move_uploaded_file($tmp, $dest)) {
                            $s = $pdo->prepare('INSERT INTO comment_images(comment_id,user_id,original_name,stored_name) VALUES(?,?,?,?)');
                            $s->execute([$commentId, $u['id'], $originalName, $stored]);
                        }
                    }
                }
                log_activity('comentou na tarefa', 'task', $id, $task['title']);
                $pdo->commit();
                $_SESSION['flash'] = 'Comentário adicionado com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
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
            flash_message('O comentário precisa ter texto ou pelo menos uma imagem.', 'error');
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
            flash_message('Imagem não encontrada.', 'error');
        } elseif ((int)$img['comment_user_id'] !== (int)$u['id']) {
            http_response_code(403);
            exit('Você não possui permissão para excluir esta imagem.');
        } else {
            $deleteWholeComment = trim((string)$img['body']) === '' && (int)$img['image_count'] <= 1;
            $pdo->beginTransaction();
            try {
                if ($deleteWholeComment) {
                    $s = $pdo->prepare('DELETE FROM comments WHERE id=? AND task_id=?');
                    $s->execute([(int)$img['comment_id'], $id]);
                    log_activity('excluiu um comentário', 'task', $id, $task['title']);
                    $_SESSION['flash'] = 'A imagem era o único conteúdo do comentário; o comentário também foi excluído.';
                } else {
                    $s = $pdo->prepare('DELETE FROM comment_images WHERE id=?');
                    $s->execute([$imageId]);
                    log_activity('removeu uma imagem de comentário', 'task', $id, $img['original_name']);
                    $_SESSION['flash'] = 'Imagem removida do comentário.';
                }
                $pdo->commit();
                remove_uploaded_file($img['stored_name']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } elseif ($action === 'subtask') {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $s = $pdo->prepare('INSERT INTO subtasks(task_id,title) VALUES(?,?)');
            $s->execute([$id, $title]);
            log_activity('adicionou uma subtarefa', 'task', $id, $title);
            $_SESSION['flash'] = 'Subtarefa adicionada.';
        }
    } elseif ($action === 'toggle_subtask') {
        $sid = (int)($_POST['subtask_id'] ?? 0);
        $s = $pdo->prepare('UPDATE subtasks SET completed=1-completed WHERE id=? AND task_id=?');
        $s->execute([$sid, $id]);
        log_activity('atualizou uma subtarefa', 'task', $id, $task['title']);
        $_SESSION['flash'] = 'Subtarefa atualizada.';
    } elseif ($action === 'upload' && isset($_FILES['file'])) {
        $f = $_FILES['file'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 10 * 1024 * 1024) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','gif','webp','txt','zip'];
            if (in_array($ext, $allowed, true)) {
                $stored = bin2hex(random_bytes(12)) . '.' . $ext;
                $dest = __DIR__ . '/uploads/' . $stored;
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    $s = $pdo->prepare('INSERT INTO attachments(task_id,user_id,original_name,stored_name) VALUES(?,?,?,?)');
                    $s->execute([$id, $u['id'], $f['name'], $stored]);
                    log_activity('anexou um arquivo', 'task', $id, $f['name']);
                    $_SESSION['flash'] = 'Arquivo anexado com sucesso.';
                }
            }
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

$s = $pdo->prepare('SELECT s.* FROM subtasks s WHERE task_id=? ORDER BY id');
$s->execute([$id]);
$subtasks = $s->fetchAll();

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
foreach ($subtasks as $st) if ($st['completed']) $doneCount++;
$progress = count($subtasks) ? round($doneCount / count($subtasks) * 100) : 0;
$canDeleteTask = $canManageTaskFiles;
$pageTitle = $task['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-head task-detail-head">
    <div class="task-detail-title">
        <a class="back" href="task_form.php">← Voltar para o quadro</a>
        <span class="task-page-eyebrow">Detalhes da tarefa</span>
        <h1><?= e($task['title']) ?></h1>
        <p class="muted">Criada por <?= e($task['creator_name']) ?> <span>•</span> <?= !empty($task['is_private']) ? '🔒 Privada' : '🌐 Pública' ?></p>
    </div>
    <div class="page-actions task-detail-actions">
        <?php if ($canDeleteTask): ?><a class="btn secondary" href="task_form.php?id=<?= $task['id'] ?>"><span>✎</span> Editar</a><?php endif; ?>
        <?php if ($canDeleteTask): ?>
        <form method="post" action="delete_task.php" onsubmit="return confirm('Tem certeza que deseja apagar esta tarefa? Comentários, subtarefas e anexos também serão apagados.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $task['id'] ?>">
            <button class="btn danger" type="submit"><span>×</span> Excluir</button>
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
            <div><span class="task-metric-icon">!</span><small>Prioridade</small><strong><?= e(priority_label($task['priority'])) ?></strong></div>
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
                <button type="button" class="image-thumb js-image-preview" data-src="uploads/<?= e($a['stored_name']) ?>" data-title="<?= e($a['original_name']) ?>">
                    <img src="uploads/<?= e($a['stored_name']) ?>" alt="<?= e($a['original_name']) ?>">
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

    <div class="panel task-section-card task-subtasks-card">
        <div class="panel-head task-section-heading"><div><span class="task-section-icon">✓</span><div><h2>Subtarefas</h2><p><?= $doneCount ?> de <?= count($subtasks) ?> concluídas</p></div></div><span class="task-progress-value"><?= $progress ?>%</span></div>
        <div class="progress"><span style="width:<?= $progress ?>%"></span></div>
        <div class="subtasks">
            <?php if (!$subtasks): ?><div class="task-inline-empty"><span>✓</span><p>Nenhuma subtarefa adicionada.</p></div><?php endif; ?>
            <?php foreach ($subtasks as $st): ?>
            <form method="post" class="subtask <?= $st['completed'] ? 'done' : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_subtask"><input type="hidden" name="subtask_id" value="<?= $st['id'] ?>">
                <?php if ($canManageTaskFiles): ?><button type="submit" class="check"><?= $st['completed'] ? '✓' : '○' ?></button><?php else: ?><span class="check"><?= $st['completed'] ? '✓' : '○' ?></span><?php endif; ?><span><?= e($st['title']) ?></span>
            </form>
            <?php endforeach; ?>
        </div>
        <?php if ($canManageTaskFiles): ?><form method="post" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="subtask"><input name="title" placeholder="Adicionar subtarefa..." required><button class="btn small">Adicionar</button>
        </form><?php endif; ?>
    </div>

    <div class="panel task-section-card task-comments-card">
        <div class="task-section-heading"><div><span class="task-section-icon">◌</span><div><h2>Comentários</h2><p>Registre atualizações e informações importantes</p></div></div><span class="task-count-pill"><?= count($comments) ?></span></div>
        <form method="post" enctype="multipart/form-data" class="comment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="comment">
            <textarea name="body" rows="3" placeholder="Escreva um comentário..."></textarea>
            <label>Adicionar imagens ao comentário</label>
            <input type="file" name="comment_images[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple>
            <div class="comment-form-footer"><p class="muted tiny">Você pode enviar várias imagens de até 10 MB.</p><button class="btn small">Publicar comentário</button></div>
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
                        <form method="post" onsubmit="return confirm('Excluir este comentário? As imagens dele também serão removidas.');">
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
                        <button type="button" class="comment-image js-image-preview" data-src="uploads/<?= e($img['stored_name']) ?>" data-title="<?= e($img['original_name']) ?>">
                            <img src="uploads/<?= e($img['stored_name']) ?>" alt="<?= e($img['original_name']) ?>">
                        </button>
                        <?php if ($canManageComment): ?>
                        <form method="post" class="comment-image-delete" onsubmit="return confirm('Excluir esta imagem do comentário?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_comment_image">
                            <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                            <button type="submit" title="Excluir imagem" aria-label="Excluir imagem">×</button>
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
            <button class="btn small">+ Enviar arquivo</button>
        </form>
        <p class="muted tiny">Até 10 MB: PDF, Word, Excel, imagens, TXT ou ZIP.</p>
        <?php endif; ?>

        <?php if ($imageAttachments): ?>
        <h3 class="attachment-subtitle">Imagens</h3>
        <div class="attachment-mini-grid">
            <?php foreach ($imageAttachments as $a): ?>
            <button type="button" class="attachment-mini js-image-preview" data-src="uploads/<?= e($a['stored_name']) ?>" data-title="<?= e($a['original_name']) ?>">
                <img src="uploads/<?= e($a['stored_name']) ?>" alt="<?= e($a['original_name']) ?>">
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($otherAttachments): ?>
        <h3 class="attachment-subtitle">Outros arquivos</h3>
        <div class="attachments">
            <?php foreach ($otherAttachments as $a): ?>
            <a href="uploads/<?= e($a['stored_name']) ?>" target="_blank"><strong><?= e($a['original_name']) ?></strong><small><?= e($a['user_name']) ?> • <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small></a>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$imageAttachments): ?>
            <p class="empty">Nenhum anexo enviado.</p>
        <?php endif; ?>
    </div>
</aside>
</div>

<div class="image-modal" id="imageModal" aria-hidden="true">
    <button type="button" class="image-modal-close" aria-label="Fechar">×</button>
    <div class="image-modal-content">
        <img src="" alt="Imagem ampliada" id="imageModalImg">
        <div class="image-modal-title" id="imageModalTitle"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
