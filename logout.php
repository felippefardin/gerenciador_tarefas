<?php
require_once __DIR__ . '/config/config.php';
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['login_message'] = 'Sessão encerrada com sucesso.';
header('Location: login.php');
exit;
