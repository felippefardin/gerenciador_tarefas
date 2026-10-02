<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/email_change.php';
require_login();
ensure_v6_schema();
ensure_v7_schema();

$pdo = db();
$user = current_user();
$error = '';
$resetError = '';
$showReset = isset($_GET['reset']);
$emailError = '';
$deleteError = '';
$showEmailChange = isset($_GET['email_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_account') {
        $password = $_POST['delete_password'] ?? '';
        $confirmation = trim($_POST['delete_confirmation'] ?? '');
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $passwordHash = (string)$stmt->fetchColumn();
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $otherAdminStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND id<>?");
        $otherAdminStmt->execute([(int)$user['id']]);
        $otherAdminCount = (int)$otherAdminStmt->fetchColumn();

        if (!password_verify($password, $passwordHash)) {
            $deleteError = 'A senha informada está incorreta.';
        } elseif ($confirmation !== 'EXCLUIR') {
            $deleteError = 'Digite EXCLUIR no campo de confirmação.';
        } elseif ($userCount <= 1) {
            $deleteError = 'A última conta do sistema não pode ser excluída.';
        } elseif (($user['role'] ?? '') === 'admin' && $otherAdminCount === 0) {
            $deleteError = 'Antes de excluir esta conta, nomeie outro administrador na página Usuários.';
        } else {
            $fallbackStmt = $pdo->prepare("SELECT id FROM users WHERE id<>? ORDER BY CASE WHEN role='admin' THEN 0 ELSE 1 END, id LIMIT 1");
            $fallbackStmt->execute([$user['id']]);
            $fallbackId = (int)$fallbackStmt->fetchColumn();

            $files = [];
            $stmt = $pdo->prepare('SELECT DISTINCT a.stored_name FROM attachments a JOIN tasks t ON t.id=a.task_id WHERE a.user_id=? OR t.creator_id=?');
            $stmt->execute([$user['id'], $user['id']]);
            $files = array_merge($files, array_column($stmt->fetchAll(), 'stored_name'));
            $stmt = $pdo->prepare('SELECT DISTINCT ci.stored_name FROM comment_images ci JOIN comments c ON c.id=ci.comment_id JOIN tasks t ON t.id=c.task_id WHERE ci.user_id=? OR c.user_id=? OR t.creator_id=?');
            $stmt->execute([$user['id'], $user['id'], $user['id']]);
            $files = array_merge($files, array_column($stmt->fetchAll(), 'stored_name'));

            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM tasks WHERE creator_id=?')->execute([$user['id']]);
                $pdo->prepare('UPDATE projects SET owner_id=? WHERE owner_id=?')->execute([$fallbackId, $user['id']]);
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user['id']]);
                $pdo->commit();
                foreach (array_unique($files) as $file) remove_uploaded_file($file);
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                redirect('login.php?account_deleted=1');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $deleteError = 'Não foi possível excluir a conta. Tente novamente.';
            }
        }
    } elseif ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $username = normalize_username($_POST['username'] ?? '');
        if ($name === '') {
            $error = 'Informe seu nome.';
        } elseif (!valid_username($username)) {
            $error = 'O nome de login deve ter de 3 a 80 caracteres e usar apenas letras, números, ponto, hífen ou underline.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
            $check->execute([$username, $user['id']]);
            if ($check->fetch()) {
                $error = 'Esse nome de login já está sendo usado por outro usuário.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET name=?, username=? WHERE id=?');
                $stmt->execute([$name, $username, $user['id']]);
                log_activity('atualizou o próprio perfil', 'user', (int)$user['id'], $username);
                $_SESSION['flash'] = 'Perfil atualizado com sucesso.';
                redirect('profile.php');
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            $error = 'A senha atual está incorreta.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'A nova senha deve ter pelo menos 8 caracteres.';
        } elseif ($newPassword !== $confirm) {
            $error = 'A confirmação da nova senha não confere.';
        } else {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            $pdo->prepare('UPDATE password_reset_codes SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);
            log_activity('alterou a própria senha', 'user', (int)$user['id'], null);
            $_SESSION['flash'] = 'Senha alterada com sucesso.';
            redirect('profile.php');
        }
    } elseif ($action === 'send_email_change_codes') {
        $showEmailChange = true;
        try {
            send_email_change_codes($user, $_POST['new_email'] ?? '');
            $_SESSION['flash'] = 'Enviamos um código ao e-mail atual e outro ao novo e-mail. Eles expiram em 15 minutos.';
            redirect('profile.php?email_change=1');
        } catch (InvalidArgumentException $e) {
            $emailError = $e->getMessage();
        } catch (Throwable $e) {
            $emailError = 'Não foi possível enviar os dois códigos. ' . $e->getMessage();
        }
    } elseif ($action === 'confirm_email_change') {
        $showEmailChange = true;
        try {
            [$ok, $result] = confirm_email_change((int)$user['id'], $_POST['current_email_code'] ?? '', $_POST['new_email_code'] ?? '');
            if ($ok) {
                log_activity('alterou o próprio e-mail', 'user', (int)$user['id'], $result);
                $_SESSION['flash'] = 'E-mail alterado para ' . $result . ' com sucesso.';
                redirect('profile.php');
            }
            $emailError = $result;
        } catch (Throwable $e) {
            $emailError = 'Não foi possível confirmar a alteração do e-mail.';
        }
    } elseif ($action === 'send_reset_code') {
        try {
            send_password_code($user, 'profile');
            $_SESSION['flash'] = 'Código enviado para ' . $user['email'] . '. Ele expira em 15 minutos.';
            redirect('profile.php?reset=1');
        } catch (Throwable $e) {
            $resetError = 'Não foi possível enviar o código. ' . $e->getMessage();
            $showReset = true;
        }
    } elseif ($action === 'reset_password_code') {
        $showReset = true;
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';
        if ($newPassword !== $confirm) {
            $resetError = 'A confirmação da nova senha não confere.';
        } else {
            [$ok, $resultMessage] = reset_password_with_code((string)$user['email'], $code, $newPassword, 'profile');
            if ($ok) {
                log_activity('redefiniu a própria senha por e-mail', 'user', (int)$user['id'], null);
                $_SESSION['flash'] = 'Senha redefinida por código com sucesso.';
                redirect('profile.php');
            }
            $resetError = $resultMessage;
        }
    }
}

$stmt = $pdo->prepare('SELECT id,name,username,email,role,created_at FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$user = $stmt->fetch();
$pageTitle = 'Meu perfil';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div><h1>Meu perfil</h1><p class="muted">Esta página é particular da sua conta.</p></div>
</div>

<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

<div class="profile-grid">
    <section class="panel">
        <h2>Dados da conta</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Nome</label>
            <input name="name" maxlength="120" value="<?= e($user['name']) ?>" required>
            <label>Nome de login</label>
            <input name="username" maxlength="80" value="<?= e($user['username']) ?>" required>
            <small class="field-help">Você pode usar o nome de login ou o e-mail para entrar no sistema.</small>
            <label>E-mail</label>
            <input value="<?= e($user['email']) ?>" disabled>
            <small class="field-help">O e-mail é usado para recuperação de senha.</small>
            <div class="profile-meta">
                <span>Perfil: <strong><?= e($user['role']) ?></strong></span>
                <span>Criado em <?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
            </div>
            <button class="btn" type="submit">Salvar perfil</button>
        </form>
    </section>

    <section class="panel">
        <h2>Alterar senha</h2>
        <p class="muted">Se souber sua senha atual, altere-a imediatamente por aqui.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>Senha atual</label>
            <input type="password" name="current_password" autocomplete="current-password" required>
            <label>Nova senha</label>
            <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
            <label>Confirmar nova senha</label>
            <input type="password" name="new_password_confirm" minlength="8" autocomplete="new-password" required>
            <button class="btn" type="submit">Alterar senha</button>
        </form>
    </section>
</div>

<section class="panel profile-email-change">
    <div class="panel-head">
        <div><h2>Alterar e-mail</h2><p class="muted">Por segurança, você precisará confirmar um código no e-mail atual e outro no novo e-mail.</p></div>
    </div>
    <?php if ($emailError): ?><div class="error"><?= e($emailError) ?></div><?php endif; ?>
    <form method="post" class="profile-email-change-request">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="send_email_change_codes">
        <div><label>E-mail atual</label><input value="<?= e($user['email']) ?>" disabled></div>
        <div><label>Novo e-mail</label><input type="email" name="new_email" value="<?= e($_POST['new_email'] ?? '') ?>" autocomplete="email" required></div>
        <div><button class="btn secondary" type="submit">Enviar os dois códigos</button></div>
    </form>

    <?php if ($showEmailChange): ?>
    <form method="post" class="profile-email-change-confirm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="confirm_email_change">
        <div><label>Código recebido no e-mail atual</label><input name="current_email_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
        <div><label>Código recebido no novo e-mail</label><input name="new_email_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
        <div><button class="btn" type="submit">Confirmar novo e-mail</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="panel profile-email-reset">
    <div class="panel-head">
        <div><h2>Redefinir senha por e-mail</h2><p class="muted">Envie um código para seu e-mail cadastrado e escolha outra senha.</p></div>
    </div>
    <?php if ($resetError): ?><div class="error"><?= e($resetError) ?></div><?php endif; ?>
    <form method="post" class="profile-code-send">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="send_reset_code">
        <button class="btn secondary" type="submit">Enviar código para <?= e($user['email']) ?></button>
    </form>

    <?php if ($showReset): ?>
    <form method="post" class="profile-code-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password_code">
        <div><label>Código de 6 dígitos</label><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></div>
        <div><label>Nova senha</label><input type="password" name="new_password" minlength="8" required></div>
        <div><label>Confirmar nova senha</label><input type="password" name="new_password_confirm" minlength="8" required></div>
        <div><button class="btn" type="submit">Redefinir com código</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="panel account-danger-zone">
    <div class="panel-head">
        <div><h2>Excluir minha conta</h2><p class="muted">Esta ação é permanente e não pode ser desfeita.</p></div>
    </div>
    <?php if ($deleteError): ?><div class="error"><?= e($deleteError) ?></div><?php endif; ?>
    <p>Suas tarefas, lembretes, comentários e arquivos serão removidos. Conteúdos públicos criados por outras pessoas continuarão disponíveis.</p>
    <form method="post" class="account-delete-form" onsubmit="return confirm('Tem certeza de que deseja excluir permanentemente sua conta e todos os seus dados?');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_account">
        <div><label>Senha atual</label><input type="password" name="delete_password" autocomplete="current-password" required></div>
        <div><label>Digite EXCLUIR para confirmar</label><input name="delete_confirmation" autocomplete="off" pattern="EXCLUIR" required></div>
        <div><button class="btn danger" type="submit">Excluir minha conta</button></div>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
