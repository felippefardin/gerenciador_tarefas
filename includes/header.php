<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
$user = current_user();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$dueSoonCount = 0;
if ($user) {
    try {
        $dueSoon = db()->prepare("SELECT COUNT(*) FROM tasks WHERE status<>'done' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND (is_private=0 OR creator_id=?)");
        $dueSoon->execute([(int)$user['id']]);
        $dueSoonCount = (int)$dueSoon->fetchColumn();
    } catch (Throwable $e) {
        $dueSoonCount = 0;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? APP_NAME) ?> - <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20261002-4">
    <link rel="stylesheet" href="assets/css/profile-email.css?v=20261002-4">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261002-4">
    <script src="assets/js/theme.js"></script>
</head>
<body>
<header class="topbar">
    <div class="brand"><a href="dashboard.php">✓ <?= APP_NAME ?></a></div>
    <?php if ($user): ?>
    <nav>
        <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <a class="<?= $currentPage === 'reminders.php' ? 'active' : '' ?>" href="reminders.php">Lembretes</a>
        <a class="<?= in_array($currentPage, ['task_form.php','task.php'], true) ? 'active' : '' ?>" href="task_form.php">Tarefas</a>
        <a class="<?= $currentPage === 'tutorial.php' ? 'active' : '' ?>" href="tutorial.php">Ajuda</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a class="<?= $currentPage === 'users.php' ? 'active' : '' ?>" href="users.php">Usuários</a><?php endif; ?>
        <?php if ($dueSoonCount > 0): ?><a class="nav-alert" href="task_form.php?filter=due_soon" title="Tarefas que vencem nos próximos 5 dias">🔔 <span><?= $dueSoonCount ?></span></a><?php endif; ?>
    </nav>
    <div class="userbox">
        <a class="profile-link" href="profile.php" title="Abrir meu perfil"><span class="profile-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span><span><?= e($user['name']) ?></span></a>
        <a href="logout.php">Sair</a>
    </div>
    <?php endif; ?>
</header>
<main class="container">
<?php $flash = take_flash(); if ($flash): ?>
    <div class="flash flash-toast flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
        <span class="flash-icon" aria-hidden="true"><?= ['success'=>'✓','error'=>'!','warning'=>'!','info'=>'i'][$flash['type']] ?></span>
        <span><?= e($flash['message']) ?></span>
        <button type="button" class="flash-close" aria-label="Fechar mensagem">×</button>
    </div>
<?php endif; ?>
