<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$loginError = '';
$registerError = '';
$loginMessage = isset($_GET['account_deleted']) ? 'Sua conta e seus dados foram excluídos com sucesso.' : ($_SESSION['login_message'] ?? '');
unset($_SESSION['login_message']);
$dbReady = true;
$networkUrl = 'http://' . NETWORK_COMPUTER_NAME . NETWORK_APP_PATH;

try {
    ensure_v6_schema();
} catch (Throwable $e) {
    $dbReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady) {
    verify_csrf();
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $username = normalize_username($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($name === '') {
            $registerError = 'Informe seu nome.';
        } elseif (!valid_username($username)) {
            $registerError = 'O nome de login deve ter de 3 a 80 caracteres e usar apenas letras, números, ponto, hífen ou underline.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $registerError = 'Informe um e-mail válido.';
        } elseif (strlen($password) < 8) {
            $registerError = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($password !== $confirm) {
            $registerError = 'A confirmação da senha não confere.';
        } else {
            try {
                $check = db()->prepare('SELECT id FROM users WHERE email=? OR username=? LIMIT 1');
                $check->execute([$email, $username]);
                if ($check->fetch()) {
                    $registerError = 'Já existe um usuário com esse e-mail ou nome de login.';
                } else {
                    $stmt = db()->prepare("INSERT INTO users(name,username,email,password,role) VALUES(?,?,?,?,'user')");
                    $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
                    $id = (int)db()->lastInsertId();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $id;
                    $_SESSION['flash'] = 'Usuário criado com sucesso. Bem-vindo ao sistema.';
                    header('Location: dashboard.php');
                    exit;
                }
            } catch (Throwable $e) {
                $registerError = 'Não foi possível criar o usuário. Verifique se o banco está atualizado e tente novamente.';
            }
        }
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
            $stmt->execute([strtolower($login), normalize_username($login)]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                flash_message('Login realizado com sucesso. Bem-vindo, ' . $user['name'] . '!', 'success');
                header('Location: dashboard.php');
                exit;
            }
            $loginError = 'E-mail, nome de login ou senha inválidos.';
        } catch (Throwable $e) {
            $loginError = 'Banco ainda não configurado. Acesse setup.php primeiro.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=20261002-4">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261002-4">
    <script src="assets/js/theme.js"></script>
    <title>Login - Gerenciador de Tarefas</title>
</head>
<body class="auth-page">
<div class="auth-shell">
    <section class="auth-card auth-login-card">
        <h1><i class="bi bi-check2-square" aria-hidden="true"></i> Gerenciador de Tarefas</h1>
        <p>Entre para acessar os projetos, tarefas e lembretes compartilhados.</p>
        <?php if (!$dbReady): ?><div class="error">Banco ainda não configurado. Abra <a href="setup.php">setup.php</a> primeiro.</div><?php endif; ?>
        <?php if ($loginMessage): ?><div class="flash"><?= e($loginMessage) ?></div><?php endif; ?>
        <?php if ($loginError): ?><div class="error"><?= e($loginError) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label>E-mail ou nome de login</label>
            <input name="login" autocomplete="username" required autofocus>
            <label>Senha</label>
            <input type="password" name="password" autocomplete="current-password" required>
            <button class="btn" type="submit" <?= !$dbReady ? 'disabled' : '' ?>>Entrar</button>
        </form>
        <div class="auth-links">
            <a href="forgot_password.php">Esqueci minha senha</a>
            <a href="setup.php">Configuração inicial</a>
        </div>
        <div class="login-help-box">
            <strong>Primeira vez por aqui?</strong>
            <p>Veja o passo a passo completo para criar sua conta, organizar projetos, tarefas e lembretes.</p>
            <a class="btn secondary" href="tutorial.php"><i class="bi bi-book" aria-hidden="true"></i> Abrir tutorial completo</a>
        </div>
        <div class="network-access-box">
            <strong>Acesso pela rede local</strong>
            <p>Em outro computador conectado à mesma rede, abra:</p>
            <a class="network-url" href="<?= e($networkUrl) ?>"><?= e($networkUrl) ?></a>
            <button class="copy-network-link" type="button" data-copy-url="<?= e($networkUrl) ?>">Copiar link</button>
            <small>O computador <?= e(NETWORK_COMPUTER_NAME) ?> precisa estar ligado, com Apache e MySQL iniciados.</small>
        </div>
    </section>

    <section class="auth-card auth-register-card">
        <h2>Criar usuário</h2>
        <p class="muted">O novo usuário terá acesso às mesmas informações compartilhadas do sistema e um perfil próprio.</p>
        <?php if ($registerError): ?><div class="error"><?= e($registerError) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="register">
            <label>Nome</label>
            <input name="name" maxlength="120" value="<?= e((($_POST['action'] ?? '') === 'register') ? ($_POST['name'] ?? '') : '') ?>" required>
            <label>Nome de login</label>
            <input name="username" maxlength="80" placeholder="Ex.: felippe" value="<?= e((($_POST['action'] ?? '') === 'register') ? ($_POST['username'] ?? '') : '') ?>" required>
            <small class="field-help">Use letras, números, ponto, hífen ou underline.</small>
            <label>E-mail</label>
            <input type="email" name="email" autocomplete="email" value="<?= e((($_POST['action'] ?? '') === 'register') ? ($_POST['email'] ?? '') : '') ?>" required>
            <label>Senha</label>
            <input type="password" name="password" minlength="8" autocomplete="new-password" required>
            <label>Confirmar senha</label>
            <input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required>
            <button class="btn" type="submit" <?= !$dbReady ? 'disabled' : '' ?>>Criar usuário</button>
        </form>
    </section>
</div>
<script>
document.querySelector('.copy-network-link')?.addEventListener('click', async function () {
    const button = this;
    try {
        await navigator.clipboard.writeText(button.dataset.copyUrl);
        button.textContent = 'Link copiado!';
    } catch (_) {
        window.prompt('Copie o endereço abaixo:', button.dataset.copyUrl);
    }
    window.setTimeout(() => { button.textContent = 'Copiar link'; }, 2200);
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
