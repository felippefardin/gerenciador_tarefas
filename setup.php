<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
$message = '';
$error = '';
$alreadyConfigured = false;

try {
    $pdoCheck = db();
    if (db_table_exists('users')) {
        $alreadyConfigured = (int)$pdoCheck->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }
} catch (Throwable $e) {
    // Instalação nova: o banco ainda não existe.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($alreadyConfigured) {
        $error = 'O sistema já possui usuários e não pode executar a configuração inicial novamente.';
    } else {
        try {
            $server = db(true);
            $sql = file_get_contents(__DIR__ . '/database/schema.sql');
            foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
                $server->exec($statement);
            }
            $pdo = db();
            $email = strtolower(trim($_POST['email'] ?? 'admin@local.test'));
            $password = $_POST['password'] ?? 'admin123';
            $name = trim($_POST['name'] ?? 'Administrador');
            $username = normalize_username($_POST['username'] ?? 'admin');

            if (!valid_username($username)) throw new RuntimeException('Informe um nome de login válido.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
            if (strlen($password) < 8) throw new RuntimeException('A senha inicial deve ter pelo menos 8 caracteres.');

            $stmt = $pdo->prepare("INSERT INTO users (name,username,email,password,role) VALUES (?,?,?,?,'admin')");
            $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            ensure_v6_schema();
            $message = 'Banco configurado com sucesso. Agora você já pode entrar no sistema.';
            $alreadyConfigured = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/theme.css"><script src="assets/js/theme.js"></script><title>Configuração</title></head><body class="auth-page">
<div class="auth-card">
<h1>Configurar <?= APP_NAME ?></h1>
<?php if ($alreadyConfigured && !$message): ?>
    <div class="flash">O sistema já está configurado.</div>
    <p>Por segurança, este assistente só pode criar o primeiro administrador.</p>
    <p><a class="btn" href="login.php">Ir para o login</a></p>
<?php else: ?>
    <p>Este assistente cria o banco MySQL e o primeiro usuário administrador.</p>
    <?php if($message): ?><div class="flash"><?= e($message) ?></div><p><a class="btn" href="login.php">Ir para o login</a></p><?php endif; ?>
    <?php if($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if(!$message): ?>
    <form method="post">
    <label>Nome</label><input name="name" value="Administrador" required>
    <label>Nome de login</label><input name="username" value="admin" required>
    <label>E-mail</label><input type="email" name="email" value="admin@local.test" required>
    <label>Senha inicial</label><input type="password" name="password" value="admin123" minlength="8" required>
    <button class="btn" type="submit">Criar banco e administrador</button>
    </form>
    <?php endif; ?>
<?php endif; ?>
</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script><script src="assets/js/app.js"></script></body></html>
