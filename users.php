<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
ensure_v7_schema();
ensure_v14_schema();

$pdo = db();
$me = current_user();
if (($me['role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }

$error = '';
$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            $error = 'Usuário inválido.';
        } elseif ($targetId === (int)$me['id']) {
            $error = 'A conta do administrador conectado não pode ser removida.';
        } else {
            $targetStmt = $pdo->prepare('SELECT id,name FROM users WHERE id=?');
            $targetStmt->execute([$targetId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                $error = 'Usuário não encontrado.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE projects SET owner_id=? WHERE owner_id=?')->execute([(int)$me['id'], $targetId]);
                    $pdo->prepare('UPDATE tasks SET creator_id=? WHERE creator_id=?')->execute([(int)$me['id'], $targetId]);
                    $pdo->prepare('UPDATE comments SET user_id=? WHERE user_id=?')->execute([(int)$me['id'], $targetId]);
                    $pdo->prepare('UPDATE attachments SET user_id=? WHERE user_id=?')->execute([(int)$me['id'], $targetId]);
                    if (db_table_exists('comment_images')) $pdo->prepare('UPDATE comment_images SET user_id=? WHERE user_id=?')->execute([(int)$me['id'], $targetId]);
                    if (db_table_exists('simple_tasks')) $pdo->prepare('UPDATE simple_tasks SET user_id=? WHERE user_id=?')->execute([(int)$me['id'], $targetId]);
                    $pdo->prepare('DELETE FROM task_assignees WHERE user_id=?')->execute([$targetId]);
                    $pdo->prepare('UPDATE tasks SET assignee_id=NULL WHERE assignee_id=?')->execute([$targetId]);
                    $pdo->prepare('UPDATE activity_logs SET user_id=? WHERE user_id=?')->execute([(int)$me['id'], $targetId]);
                    log_activity('removeu um usuário', 'user', $targetId, (string)$target['name']);
                    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$targetId]);
                    $pdo->commit();
                    $_SESSION['flash'] = 'Usuário removido. Os conteúdos dele foram transferidos para sua conta administrativa.';
                    redirect('users.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Não foi possível remover o usuário. Nenhuma alteração foi aplicada.';
                }
            }
        }
    } else {
        $targetId = $action === 'update' ? (int)($_POST['user_id'] ?? 0) : 0;
        $name = trim($_POST['name'] ?? '');
        $username = normalize_username($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? 'user', ['user','admin'], true) ? $_POST['role'] : 'user';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Preencha nome e e-mail corretamente.';
        } elseif (!valid_username($username)) {
            $error = 'O nome de login deve ter de 3 a 80 caracteres e usar apenas letras, números, ponto, hífen ou underline.';
        } elseif ($action === 'create' && strlen($password) < 8) {
            $error = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($action === 'update' && $password !== '' && strlen($password) < 8) {
            $error = 'A nova senha deve ter pelo menos 8 caracteres.';
        } elseif ($action === 'update' && $targetId === (int)$me['id'] && $role !== 'admin') {
            $error = 'O administrador conectado não pode retirar o próprio acesso administrativo.';
        } else {
            try {
                if ($action === 'update') {
                    $exists = $pdo->prepare('SELECT id FROM users WHERE id=?');
                    $exists->execute([$targetId]);
                    if (!$exists->fetch()) throw new RuntimeException('Usuário não encontrado.');
                    if ($password !== '') {
                        $stmt = $pdo->prepare('UPDATE users SET name=?,username=?,email=?,password=?,role=? WHERE id=?');
                        $stmt->execute([$name,$username,$email,password_hash($password,PASSWORD_DEFAULT),$role,$targetId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET name=?,username=?,email=?,role=? WHERE id=?');
                        $stmt->execute([$name,$username,$email,$role,$targetId]);
                    }
                    log_activity('editou um usuário','user',$targetId,$name);
                    $_SESSION['flash'] = 'Usuário atualizado com sucesso.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO users(name,username,email,password,role) VALUES(?,?,?,?,?)');
                    $stmt->execute([$name,$username,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
                    log_activity('criou um usuário','user',(int)$pdo->lastInsertId(),$name);
                    $_SESSION['flash'] = 'Usuário criado.';
                }
                redirect('users.php');
            } catch (Throwable $e) {
                $error = $action === 'update' ? 'Não foi possível atualizar. Verifique se o e-mail ou nome de login já pertence a outra conta.' : 'Não foi possível criar o usuário. Verifique se o e-mail ou nome de login já existe.';
                $editId = $targetId;
            }
        }
    }
}

if ($editId) {
    $stmt = $pdo->prepare('SELECT id,name,username,email,role,created_at FROM users WHERE id=?');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch() ?: null;
    if (!$editUser) $editId = 0;
}

$users = $pdo->query('SELECT id,name,username,email,role,created_at FROM users ORDER BY name')->fetchAll();
$roleLabels = ['user'=>'Usuário','admin'=>'Administrador'];
$pageTitle = 'Usuários';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><div><h1>Usuários</h1><p class="muted">Cadastre, edite e gerencie quem pode acessar o sistema.</p></div></div>
<div class="two-col uneven users-layout">
<section class="panel users-form-panel">
    <div class="panel-head"><h2><?= $editUser ? 'Editar usuário' : 'Novo usuário' ?></h2><?php if ($editUser): ?><a href="users.php">Cancelar</a><?php endif; ?></div>
    <?php if($error):?><div class="error"><?=e($error)?></div><?php endif;?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
        <?php if ($editUser): ?><input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
        <label>Nome</label><input name="name" maxlength="120" value="<?= e($editUser['name'] ?? '') ?>" required>
        <label>Nome de login</label><input name="username" maxlength="80" placeholder="Ex.: felippe" value="<?= e($editUser['username'] ?? '') ?>" required>
        <label>E-mail</label><input type="email" name="email" value="<?= e($editUser['email'] ?? '') ?>" required>
        <label><?= $editUser ? 'Nova senha (opcional)' : 'Senha' ?></label><input type="password" name="password" minlength="8" <?= $editUser ? '' : 'required' ?> autocomplete="new-password">
        <?php if ($editUser): ?><small class="field-help">Deixe em branco para manter a senha atual.</small><?php endif; ?>
        <label>Perfil</label>
        <select name="role" <?= $editUser && (int)$editUser['id'] === (int)$me['id'] ? 'disabled' : '' ?>><?php foreach($roleLabels as $value=>$label): ?><option value="<?= $value ?>" <?= ($editUser['role'] ?? 'user') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <?php if ($editUser && (int)$editUser['id'] === (int)$me['id']): ?><input type="hidden" name="role" value="admin"><small class="field-help">Seu próprio perfil administrativo está protegido.</small><?php endif; ?>
        <button class="btn" type="submit"><?= $editUser ? 'Salvar alterações' : 'Criar usuário' ?></button>
    </form>
</section>
<section class="panel users-list-panel">
    <div class="panel-head"><div><h2>Usuários cadastrados</h2><span class="muted tiny"><?= count($users) ?> conta<?= count($users) === 1 ? '' : 's' ?></span></div></div>
    <div class="list users-list">
    <?php foreach($users as $usr): $isMe = (int)$usr['id'] === (int)$me['id']; ?>
        <div class="list-row user-list-row">
            <div class="user-list-identity"><strong><?=e($usr['name'])?><?= $isMe ? ' (você)' : '' ?></strong><small>@<?= e($usr['username']) ?> • <?=e($usr['email'])?></small></div>
            <span class="badge"><?=e($roleLabels[$usr['role']] ?? $usr['role'])?></span>
            <div class="user-row-actions">
                <a class="btn secondary small" href="users.php?edit=<?= (int)$usr['id'] ?>">Editar</a>
                <?php if (!$isMe): ?>
                <form method="post" onsubmit="return confirm('Remover este usuário? Os conteúdos serão transferidos para sua conta administrativa.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= (int)$usr['id'] ?>">
                    <button class="btn danger small" type="submit">Remover</button>
                </form>
                <?php else: ?><span class="user-protected-label">Conta protegida</span><?php endif; ?>
            </div>
        </div>
    <?php endforeach;?>
    </div>
</section>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
