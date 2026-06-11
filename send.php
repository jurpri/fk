<?php
header('Content-Type: application/json; charset=utf-8');

function smtp_send(string $to, string $subject, string $body, string $replyTo = ''): bool {
    $host = getenv('SMTP_HOST') ?: 'smtp.office365.com';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USER') ?: '';
    $pass = getenv('SMTP_PASS') ?: '';
    $from = getenv('SMTP_FROM') ?: $user;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp  = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;

    $r = function () use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $w = fn(string $s) => fputs($fp, $s . "\r\n");

    $r(); // Server-Banner
    $w("EHLO localhost");
    $ehlo = '';
    while ($line = fgets($fp, 1024)) {
        $ehlo .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }

    if (str_contains($ehlo, 'STARTTLS')) {
        $w("STARTTLS");
        $r();
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $w("EHLO localhost");
        while ($line = fgets($fp, 1024)) {
            if (isset($line[3]) && $line[3] === ' ') break;
        }
    }

    $w("AUTH LOGIN");         $r();
    $w(base64_encode($user)); $r();
    $w(base64_encode($pass));
    $auth = $r();
    if (!str_starts_with(trim($auth), '235')) { fclose($fp); return false; }

    $w("MAIL FROM:<{$from}>"); $r();
    $w("RCPT TO:<{$to}>");     $r();
    $w("DATA");                $r();

    $headers  = "Date: " . date('r') . "\r\n";
    $headers .= "From: Fleischerei =?UTF-8?B?" . base64_encode('Kröppel') . "?= <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    if ($replyTo) $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    $w($headers . "\r\n" . chunk_split(base64_encode($body)));
    $w(".");
    $result = $r();

    $w("QUIT");
    fclose($fp);

    return str_starts_with(trim($result), '250');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

$vorname  = trim($_POST['vorname']  ?? '');
$nachname = trim($_POST['nachname'] ?? '');
$email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$nachricht = trim($_POST['nachricht'] ?? '');

if (!$vorname || !$email || !$nachricht) {
    http_response_code(400);
    die(json_encode(['error' => 'Pflichtfelder fehlen']));
}

$to      = getenv('MAIL_TO') ?: 'fleischerei-kroeppel@outlook.at';
$subject = "Neue Anfrage von {$vorname} {$nachname}";
$body    = "Neue Nachricht über das Kontaktformular:\n\n"
         . "Name:    {$vorname} {$nachname}\n"
         . "E-Mail:  {$email}\n"
         . "--------\n"
         . $nachricht;

if (smtp_send($to, $subject, $body, $email)) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Sendefehler']);
}
