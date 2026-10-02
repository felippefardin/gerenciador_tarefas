<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

function send_email_change_codes(array $user, string $newEmail): void
{
    ensure_v7_schema();
    $newEmail = strtolower(trim($newEmail));
    $currentEmail = strtolower((string)$user['email']);

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe um novo e-mail válido.');
    }
    if ($newEmail === $currentEmail) {
        throw new InvalidArgumentException('O novo e-mail deve ser diferente do atual.');
    }
    $check = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
    $check->execute([$newEmail, $user['id']]);
    if ($check->fetch()) {
        throw new InvalidArgumentException('Esse e-mail já pertence a outro usuário.');
    }

    $recent = db()->prepare('SELECT created_at FROM email_change_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $recent->execute([$user['id']]);
    $last = $recent->fetchColumn();
    if ($last && strtotime((string)$last) > time() - 60) {
        throw new RuntimeException('Aguarde cerca de 1 minuto antes de solicitar novos códigos.');
    }

    db()->prepare('UPDATE email_change_requests SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);
    $currentCode = (string)random_int(100000, 999999);
    $newCode = (string)random_int(100000, 999999);
    $stmt = db()->prepare('INSERT INTO email_change_requests(user_id,new_email,current_code_hash,new_code_hash,expires_at) VALUES(?,?,?,?,?)');
    $stmt->execute([
        $user['id'],
        $newEmail,
        password_hash($currentCode, PASSWORD_DEFAULT),
        password_hash($newCode, PASSWORD_DEFAULT),
        date('Y-m-d H:i:s', time() + 15 * 60),
    ]);

    $requestId = (int)db()->lastInsertId();
    $safeName = e($user['name'] ?? 'Usuário');
    $currentHtml = '<div style="font-family:Roboto,Arial,sans-serif;color:#222;line-height:1.6">'
        . '<h2>Confirme a troca do seu e-mail</h2><p>Olá, ' . $safeName . '.</p>'
        . '<p>Este é o código enviado ao seu <strong>e-mail atual</strong>:</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;padding:16px 18px;background:#f3f5f7;border-radius:8px;display:inline-block">' . $currentCode . '</div>'
        . '<p>Ele expira em 15 minutos. Se você não solicitou a troca, ignore este e-mail.</p></div>';
    $newHtml = '<div style="font-family:Roboto,Arial,sans-serif;color:#222;line-height:1.6">'
        . '<h2>Confirme seu novo e-mail</h2><p>Olá, ' . $safeName . '.</p>'
        . '<p>Este é o código enviado ao seu <strong>novo e-mail</strong>:</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;padding:16px 18px;background:#f3f5f7;border-radius:8px;display:inline-block">' . $newCode . '</div>'
        . '<p>Digite os dois códigos em Meu perfil para concluir a alteração.</p></div>';

    try {
        smtp_send_mail($currentEmail, (string)$user['name'], 'Código do e-mail atual', $currentHtml);
        smtp_send_mail($newEmail, (string)$user['name'], 'Código do novo e-mail', $newHtml);
    } catch (Throwable $e) {
        db()->prepare('UPDATE email_change_requests SET used_at=NOW() WHERE id=?')->execute([$requestId]);
        throw $e;
    }
}

function confirm_email_change(int $userId, string $currentCode, string $newCode): array
{
    ensure_v7_schema();
    if (!preg_match('/^\d{6}$/', trim($currentCode)) || !preg_match('/^\d{6}$/', trim($newCode))) {
        return [false, 'Informe os dois códigos de 6 dígitos.'];
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT * FROM email_change_requests WHERE user_id=? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId]);
        $request = $stmt->fetch();
        if (!$request || strtotime((string)$request['expires_at']) < time()) {
            db()->rollBack();
            return [false, 'Os códigos são inválidos ou expiraram. Solicite novos códigos.'];
        }
        if ((int)$request['attempts'] >= 5) {
            db()->rollBack();
            return [false, 'A solicitação foi bloqueada apó várias tentativas. Solicite novos códigos.'];
        }
        $validCurrent = password_verify(trim($currentCode), (string)$request['current_code_hash']);
        $validNew = password_verify(trim($newCode), (string)$request['new_code_hash']);
        if (!$validCurrent || !$validNew) {
            db()->prepare('UPDATE email_change_requests SET attempts=attempts+1 WHERE id=?')->execute([$request['id']]);
            db()->commit();
            return [false, 'Um ou ambos os códigos estão incorretos.'];
        }

        $check = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
        $check->execute([$request['new_email'], $userId]);
        if ($check->fetch()) {
            db()->rollBack();
            return [false, 'O novo e-mail passou a ser usado por outro usuário. Escolha outro endereço.'];
        }

        db()->prepare('UPDATE users SET email=? WHERE id=?')->execute([$request['new_email'], $userId]);
        db()->prepare('UPDATE email_change_requests SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        db()->prepare('UPDATE password_reset_codes SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        db()->commit();
        return [true, (string)$request['new_email']];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
