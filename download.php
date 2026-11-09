<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$kind = $_GET['kind'] ?? '';
$id = (int)($_GET['id'] ?? 0);
if ($kind === 'attachment') {
    $sql = 'SELECT a.original_name,a.stored_name,t.creator_id,t.is_private FROM attachments a JOIN tasks t ON t.id=a.task_id WHERE a.id=?';
} elseif ($kind === 'comment') {
    $sql = 'SELECT a.original_name,a.stored_name,t.creator_id,t.is_private FROM comment_images a JOIN comments c ON c.id=a.comment_id JOIN tasks t ON t.id=c.task_id WHERE a.id=?';
} else {
    http_response_code(404); exit('Arquivo não encontrado.');
}
$stmt = db()->prepare($sql);
$stmt->execute([$id]);
$file = $stmt->fetch();
$u = current_user();
if (!$file || (!empty($file['is_private']) && (int)$file['creator_id'] !== (int)$u['id'])) {
    http_response_code(404); exit('Arquivo não encontrado.');
}
$path = __DIR__ . '/uploads/' . basename($file['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Arquivo não encontrado.'); }
$type = 'application/octet-stream';
$disposition = 'attachment';
// Somente imagens raster reconhecidas podem abrir no navegador.
if (is_image_name($file['original_name'])) {
    $info = @getimagesize($path);
    if ($info && in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/gif','image/webp'], true)) {
        $type = $info['mime'];
        $disposition = 'inline';
    }
}
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . $disposition . '; filename="arquivo"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('Content-Length: ' . filesize($path));
session_write_close();
readfile($path);
