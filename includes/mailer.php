<?php
require_once __DIR__ . '/../config/mail.php';

function smtp_read_response($socket): array
{
    $lines = [];
    $code = 0;
    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') break;
        }
    }
    return [$code, implode("\n", $lines)];
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Falha SMTP (' . $code . '): ' . $response);
    }
    return $response;
}

function smtp_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('E-mail de destino inválido.');
    }

    $sslOptions = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ];
    $phpDir = dirname(PHP_BINARY);
    $caCandidates = [
        ini_get('openssl.cafile') ?: '',
        ini_get('curl.cainfo') ?: '',
        $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
        dirname($phpDir) . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt',
    ];
    foreach ($caCandidates as $caFile) {
        if ($caFile && is_file($caFile)) {
            $sslOptions['cafile'] = $caFile;
            break;
        }
    }
    $context = stream_context_create(['ssl' => $sslOptions]);

    $socket = @stream_socket_client(
        'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException('Não foi possível conectar ao servidor de e-mail: ' . $errstr . ' (' . $errno . ').');
    }

    stream_set_timeout($socket, 20);

    try {
        [$code, $response] = smtp_read_response($socket);
        if ($code !== 220) throw new RuntimeException('Servidor SMTP recusou a conexão: ' . $response);

        $hostname = gethostname() ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $hostname, [250]);

        if (SMTP_SECURE === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('Não foi possível ativar a conexão TLS com o servidor SMTP. Verifique se a extensão OpenSSL do PHP está habilitada.');
            }
            smtp_command($socket, 'EHLO ' . $hostname, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode(SMTP_USER), [334]);
        smtp_command($socket, base64_encode(SMTP_PASS), [235]);
        smtp_command($socket, 'MAIL FROM:<' . SMTP_FROM_EMAIL . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . smtp_encode_header(SMTP_FROM_NAME) . ' <' . SMTP_FROM_EMAIL . '>',
            'To: ' . ($toName !== '' ? smtp_encode_header($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: ' . smtp_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@localhost>',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody;
        $message = preg_replace('/(?m)^\./', '..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        [$code, $response] = smtp_read_response($socket);
        if ($code !== 250) throw new RuntimeException('O servidor SMTP não aceitou a mensagem: ' . $response);

        @smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}
