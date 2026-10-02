<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/password_reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: profile.php');
    exit;
}

$error = '';
$message = '';
$email = strtolower(trim($_POST['email'] ?? ''));

try {
    ensure_v6_schema();
} catch (Throwable $e) {
    $error = 'Banco ainda não configurado. Acesse setup.php primeiro.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    verify_csrf();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido.';
    } else {
        $user = find_user_by_email($email);
        if ($user) {
            try {
                send_password_code($user, 'forgot');
                $_SESSION['reset_email'] = $email;
                $_SESSION['flash_reset'] = 'Código enviado. Verifique seu e-mail e informe os 6 dígitos para criar uma nova senha.';
                redirect('reset_password.php');
            } catch (Throwable $e) {
                $error = 'Não foi possível enviar o código por e-mail. ' . $e->getMessage();
            }
        } else {
            // Resposta genérica para não revelar quais endereços estão cadastrados.
            $_SESSION['reset_email'] = $email;
            $_SESSION['flash_reset'] = 'Se esse e-mail estiver cadastrado, um código será enviado. Informe o código recebido para continuar.';
            redirect('reset_password.php');
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/theme.js"></script>
    <title>Esqueci minha senha</title>
</head>
<body class="auth-page">
<div class="auth-card">
    <h1>Recuperar senha</h1>
    <p>Informe o e-mail cadastrado. Você receberá um código de 6 dígitos.</p>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="flash"><?= e($message) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
        <button class="btn" type="submit">Enviar código</button>
    </form>
    <div class="auth-links"><a href="login.php">← Voltar ao login</a></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
