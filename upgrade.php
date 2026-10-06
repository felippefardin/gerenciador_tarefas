<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$user = current_user();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }
$message = '';
$error = '';
try {
    ensure_v2_schema();
    ensure_v4_schema();
    ensure_v6_schema();
    ensure_v7_schema();
    ensure_v14_schema();
    ensure_v17_schema();
    ensure_v22_schema();
    ensure_v23_schema();
    $message = 'Banco atualizado com sucesso para a versão atual.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$pageTitle = 'Atualização';
require __DIR__ . '/includes/header.php';
?>
<section class="panel form-panel">
    <h1>Atualização do sistema</h1>
    <?php if ($message): ?><div class="flash"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <p>As estruturas de comentários, lembretes, notificações, arquivamento de tarefas, contas, recuperação de senha, múltiplos responsáveis e as regras de Usuário/Administrador foram verificadas.</p>
    <a class="btn" href="dashboard.php">Voltar ao Dashboard</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
