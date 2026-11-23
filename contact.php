<?php
/**
 * Contact form handler for aluromero.com
 * Sends inquiries to the atelier mailbox via authenticated SMTP (PHPMailer).
 *
 * Credentials are NOT in this repo. They live in a file outside public_html:
 *   /home/<cpanel-user>/alu-contact-config.php   (one level above public_html)
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require __DIR__ . '/lib/phpmailer/Exception.php';
require __DIR__ . '/lib/phpmailer/PHPMailer.php';
require __DIR__ . '/lib/phpmailer/SMTP.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$lang = (($_POST['lang'] ?? '') === 'es') ? 'es' : 'fr';

function respond(bool $ok, string $code = 'ok', int $status = 200): void {
    global $wantsJson, $lang;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'code' => $code]);
    } else {
        $q = ['form' => $ok ? 'sent' : $code];
        if ($lang === 'es') { $q = ['lang' => 'es'] + $q; }
        header('Location: /?' . http_build_query($q) . '#contact', true, 303);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /#contact', true, 303);
    exit;
}

/* ---- Bot checks: hidden field + minimum fill time (silently "succeed") ---- */
if (trim((string)($_POST['ar_hp_note'] ?? '')) !== '') { error_log('contact.php: dropped (hidden field filled)'); respond(true); }
if (isset($_POST['t']) && is_numeric($_POST['t']) && (float)$_POST['t'] < 3) { error_log('contact.php: dropped (submitted in under 3s)'); respond(true); }

/* ---- Rate limit: 5 submissions per IP per hour ---- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rlFile = sys_get_temp_dir() . '/alu_contact_' . hash('sha256', $ip . '|aluromero');
$now = time();
$hits = [];
if (is_file($rlFile)) {
    $hits = array_filter((array)json_decode((string)@file_get_contents($rlFile), true), fn($t) => is_int($t) && $t > $now - 3600);
}
if (count($hits) >= 5) { respond(false, 'rate', 429); }

/* ---- Validate ---- */
$oneLine = fn(string $s, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? ''), 0, $max);

$name    = $oneLine((string)($_POST['name'] ?? ''), 100);
$email   = $oneLine((string)($_POST['email'] ?? ''), 200);
$type    = (string)($_POST['type'] ?? '');
$size    = $oneLine((string)($_POST['size'] ?? ''), 100);
$message = mb_substr(trim(str_replace("\0", '', (string)($_POST['message'] ?? ''))), 0, 5000);

$types = [
    'original'   => 'Adquirir una obra existente',
    'commission' => 'Encargo a medida',
    'trade'      => 'Diseñador/a o arquitecto/a',
    'other'      => 'Otro',
];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($types[$type]) || mb_strlen($message) < 10) {
    respond(false, 'invalid', 422);
}

/* ---- Config (outside public_html) ---- */
$cfgPath = dirname(__DIR__) . '/alu-contact-config.php';
if (!is_file($cfgPath)) {
    error_log('contact.php: missing config at ' . $cfgPath);
    respond(false, 'error', 500);
}
$cfg = require $cfgPath;

/* ---- Send ---- */
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['smtp_host'];
    $mail->Port       = (int)$cfg['smtp_port'];
    $mail->SMTPAuth   = (bool)$cfg['smtp_auth'];
    if ($mail->SMTPAuth) {
        $mail->Username = $cfg['smtp_user'];
        $mail->Password = $cfg['smtp_pass'];
    }
    $secure = $cfg['smtp_secure'] ?? '';
    if ($secure === 'ssl')      { $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; }
    elseif ($secure === 'tls')  { $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; }
    else                        { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
    $mail->Timeout  = 15;
    $mail->CharSet  = PHPMailer::CHARSET_UTF8;

    $mail->setFrom($cfg['from'], 'aluromero.com');
    $mail->addAddress($cfg['to']);
    $mail->addReplyTo($email, $name);

    $mail->Subject = 'Nueva solicitud desde aluromero.com — ' . $types[$type] . ' — ' . $name;
    $mail->isHTML(false);
    $mail->Body =
        "Nueva solicitud desde el formulario de aluromero.com\n"
      . "------------------------------------------------\n\n"
      . "Nombre:    {$name}\n"
      . "Correo:    {$email}\n"
      . "Proyecto:  {$types[$type]}\n"
      . "Medidas:   " . ($size !== '' ? $size : '—') . "\n"
      . "Idioma:    " . strtoupper($lang) . "\n\n"
      . "Mensaje:\n{$message}\n\n"
      . "------------------------------------------------\n"
      . "Responde directamente a este correo para contestarle a {$name}.\n";

    $mail->send();
} catch (MailException $e) {
    error_log('contact.php: send failed: ' . $mail->ErrorInfo);
    respond(false, 'error', 502);
}

$hits[] = $now;
@file_put_contents($rlFile, json_encode(array_values($hits)), LOCK_EX);
respond(true);
