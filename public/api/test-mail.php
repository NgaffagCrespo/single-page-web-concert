<?php
// Test de la configuration SMTP — en ligne de commande uniquement :
//   php public/api/test-mail.php                 -> teste la connexion
//   php public/api/test-mail.php vous@exemple.com [fr|en] -> envoie un email d'exemple
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/email_template.php';
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$s = $CONFIG['smtp'];
$m = new PHPMailer(true);
$m->isSMTP();
$m->Host = $s['host'];
$m->Port = (int) $s['port'];
$secure = $s['secure'] ?? ((int) $s['port'] === 465 ? 'ssl' : 'tls');
$m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
$m->SMTPAutoTLS = $secure !== '';
$m->SMTPAuth = $s['user'] !== '';
$m->Username = $s['user'];
$m->Password = $s['pass'];
$m->CharSet = 'UTF-8';
try {
    $m->smtpConnect();
    echo "✔ Connexion SMTP OK ({$s['host']}:{$s['port']}, {$s['user']})\n";
    $m->smtpClose();
    if ($to = $argv[1] ?? null) {
        $mail = build_tickets_email('Client Test', 'gold', [701, 702], (string) ($CONFIG['site_url'] ?? ''), false, '', $argv[2] ?? 'fr'); // 2e argument facultatif : fr ou en
        $m->setFrom($s['from'], $s['from_name']);
        $m->addAddress($to);
        $m->Subject = '[TEST] ' . $mail['subject'];
        $m->isHTML(true);
        $m->Body = $mail['html'];
        $m->AltBody = $mail['text'];
        $m->send();
        echo "✔ Email de test envoyé à $to\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, '✘ Échec : ' . $e->getMessage() . "\n");
    exit(1);
}
