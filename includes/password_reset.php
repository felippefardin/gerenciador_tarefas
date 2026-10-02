<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

function find_user_by_email(string $email): ?array
{
    ensure_v6_schema();
    $stmt = db()->prepare('SELECT id, name, username, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function send_password_code(array $user, string $purpose = 'forgot'): void
{
    ensure_v6_schema();
    $purpose = in_array($purpose, ['forgot', 'profile'], true) ? $purpose : 'forgot';

    $recent = db()->prepare('SELECT created_at FROM password_reset_codes WHERE user_id=? AND purpose=? ORDER BY id DESC LIMIT 1');
    $recent->execute([$user['id'], $purpose]);
    $last = $recent->fetchColumn();
    if ($last && strtotime((string)$last) > time() - 60) {
        throw new RuntimeException('Aguarde cerca de 1 minuto antes de solicitar outro código.');
    }

    db()->prepare('UPDATE password_reset_codes SET used_at=NOW() WHERE user_id=? AND purpose=? AND used_at IS NULL')
        ->execute([$user['id'], $purpose]);

    $code = (string)random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);
    $stmt = db()->prepare('INSERT INTO password_reset_codes(user_id,purpose,code_hash,expires_at) VALUES(?,?,?,?)');
    $stmt->execute([$user['id'], $purpose, password_hash($code, PASSWORD_DEFAULT), $expiresAt]);

    $safeName = e($user['name'] ?? 'Usuário');
    $html = '<div style="font-family:Roboto,Arial,sans-serif;color:#222;line-height:1.6">'
        . '<h2 style="margin-bottom:6px">Código para redefinir sua senha</h2>'
        . '<p>Olá, ' . $safeName . '.</p>'
        . '<p>Use o código abaixo no Gerenciador de Tarefas:</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;padding:16px 18px;background:#f3f5f7;border-radius:8px;display:inline-block">' . $code . '</div>'
        . '<p>Esse código expira em <strong>15 minutos</strong> e só pode ser usado uma vez.</p>'
        . '<p style="color:#666;font-size:13px">Se você não solicitou a alteração, ignore este e-mail.</p>'
        . '</div>';

    try {
        smtp_send_mail((string)$user['email'], (string)$user['name'], 'Código para redefinir sua senha', $html);
    } catch (Throwable $e) {
        db()->prepare('UPDATE password_reset_codes SET used_at=NOW() WHERE user_id=? AND purpose=? AND used_at IS NULL')
            ->execute([$user['id'], $purpose]);
        throw $e;
    }
}

function reset_password_with_code(string $email, string $code, string $newPassword, string $purpose = 'forgot'): array
{
    ensure_v6_schema();
    $email = strtolower(trim($email));
    $code = trim($code);
    $purpose = in_array($purpose, ['forgot', 'profile'], true) ? $purpose : 'forgot';

    if (strlen($newPassword) < 8) {
        return [false, 'A nova senha deve ter pelo menos 8 caracteres.'];
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return [false, 'Informe o código de 6 dígitos enviado por e-mail.'];
    }

    $stmt = db()->prepare("SELECT pr.*, u.email
        FROM password_reset_codes pr
        JOIN users u ON u.id=pr.user_id
        WHERE u.email=? AND pr.purpose=? AND pr.used_at IS NULL
        ORDER BY pr.id DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();

    if (!$row || strtotime((string)$row['expires_at']) < time()) {
        return [false, 'Código inválido ou expirado. Solicite um novo código.'];
    }
    if ((int)$row['attempts'] >= 5) {
        return [false, 'Este código foi bloqueado após várias tentativas. Solicite um novo código.'];
    }
    if (!password_verify($code, (string)$row['code_hash'])) {
        db()->prepare('UPDATE password_reset_codes SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);
        return [false, 'Código incorreto.'];
    }

    db()->beginTransaction();
    try {
        db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $row['user_id']]);
        db()->prepare('UPDATE password_reset_codes SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$row['user_id']]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    return [true, 'Senha alterada com sucesso.'];
}
