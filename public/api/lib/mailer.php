<?php
// Envoi d'emails (SMTP, PHPMailer) — utilisé pour la réservation et l'envoi des billets.
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoie un email. $attachments : liste de ['name' => ..., 'content' => octets, 'type' => mime].
 * Lève une exception en cas d'échec.
 */
function send_mail(array $config, string $to, string $toName, array $mail, array $attachments = []): void
{
    $smtp = $config['smtp'];
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $smtp['host'];
    $m->Port = (int) $smtp['port'];
    // 'secure' : 'ssl' (port 465), 'tls' (STARTTLS, port 587) ou '' (aucun, tests locaux)
    $secure = $smtp['secure'] ?? ((int) $smtp['port'] === 465 ? 'ssl' : 'tls');
    $m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
    $m->SMTPAutoTLS = $secure !== '';
    $m->SMTPAuth = $smtp['user'] !== '';
    $m->Username = $smtp['user'];
    $m->Password = $smtp['pass'];
    $m->CharSet = 'UTF-8';
    $m->Timeout = 20;
    $m->setFrom($smtp['from'], $smtp['from_name']);
    $m->addAddress($to, $toName);
    $m->Subject = $mail['subject'];
    $m->isHTML(true);
    $m->Body = $mail['html'];
    $m->AltBody = $mail['text'];
    foreach ($attachments as $a) {
        $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type'] ?? 'application/octet-stream');
    }
    $m->send();
}
