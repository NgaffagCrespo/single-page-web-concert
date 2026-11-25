<?php
// POST /api/reserve.php — réservation en ligne (statut RESERVED).
// Le client paie ensuite sur Maviance (nouvel onglet) ; l'organisateur confirme
// le paiement depuis son espace, ce qui envoie les billets PDF par email.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/email_template.php';
require __DIR__ . '/lib/mailer.php';

const RESERVE_MAX_PER_HOUR = 10; // par adresse IP (anti-abus)

$in = read_json(5000);

// Messages d'erreur dans la langue de la page (français par défaut)
const RESERVE_EN = [
    'Gamme de billet invalide.' => 'Invalid ticket category.',
    'Nombre de billets invalide.' => 'Invalid number of tickets.',
    'Indiquez votre nom complet.' => 'Please enter your full name.',
    'Numéro de téléphone invalide (9 chiffres minimum).' => 'Invalid phone number (at least 9 digits).',
    'Adresse email invalide.' => 'Invalid email address.',
    'Trop de réservations depuis cette connexion. Réessayez dans une heure ou contactez-nous.' => 'Too many bookings from this connection. Please try again in an hour or contact us.',
    'Erreur serveur. Réessayez dans un instant.' => 'Server error. Please try again in a moment.',
    'Cette catégorie est complète.' => 'This category is sold out.',
];
$lang = ($in['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';
function fail(int $status, string $message): void
{
    global $lang; // langue de la page du client (aussi utilisée pour ses emails)
    if ($lang === 'en') {
        $message = RESERVE_EN[$message]
            ?? (preg_match('/^Il ne reste que (\d+) billet/', $message, $m) ? "Only {$m[1]} ticket(s) left in this category." : $message);
    }
    respond($status, ['error' => $message]);
}


$tierId = (string) ($in['tierId'] ?? '');
$qty = $in['quantity'] ?? null;
$name = trim((string) ($in['name'] ?? ''));
$phone = trim((string) ($in['phone'] ?? ''));
$email = trim((string) ($in['email'] ?? ''));

if (!isset(TIERS[$tierId])) {
    fail(400, 'Gamme de billet invalide.');
}
if (!is_int($qty) || $qty < 1 || $qty > (int) $CONFIG['max_tickets_per_order']) {
    fail(400, 'Nombre de billets invalide.');
}
if (mb_strlen($name) < 3) {
    fail(400, 'Indiquez votre nom complet.');
}
if (!preg_match('/^\+?[0-9][0-9 .\-]{8,18}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 9) {
    fail(400, 'Numéro de téléphone invalide (9 chiffres minimum).');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'Adresse email invalide.');
}

try {
    $result = with_db($CONFIG, function (array &$db) use ($tierId, $qty, $name, $phone, $email, $lang, $CONFIG) {
        // Limite par IP (dans le registre, sous le même verrou)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
        $recent = array_values(array_filter($db['reserveRate'][$ip] ?? [], fn ($t) => $t > time() - 3600));
        if (count($recent) >= RESERVE_MAX_PER_HOUR) {
            return ['ok' => false, 'status' => 429, 'error' => 'Trop de réservations depuis cette connexion. Réessayez dans une heure ou contactez-nous.'];
        }
        $r = create_reservation($db, ['tierId' => $tierId, 'quantity' => $qty, 'holder' => $name, 'phone' => $phone, 'email' => $email, 'lang' => $lang]);
        if ($r['ok']) {
            $recent[] = time();
            $db['reserveRate'][$ip] = $recent;
            refresh_sales_file($CONFIG, $db);
            $r['order'] = $db['orders'][$r['orderId']];
        }
        return $r;
    });
} catch (Throwable $e) {
    error_log('[billetterie] ' . $e->getMessage());
    fail(500, 'Erreur serveur. Réessayez dans un instant.');
}
if (!$result['ok']) {
    fail($result['status'] ?? 409, $result['error']);
}

// Email de réservation (sans billet) : un échec d'envoi n'annule pas la réservation
$emailed = false;
if ($email !== '') {
    try {
        $mail = build_tickets_email($name, $tierId, $result['numbers'], (string) ($CONFIG['site_url'] ?? ''), true, $result['orderId'], $lang);
        send_mail($CONFIG, $email, $name, $mail);
        $emailed = true;
    } catch (Throwable $e) {
        error_log('[billetterie] email de réservation non envoyé (' . $result['orderId'] . ') : ' . $e->getMessage());
    }
}

respond(200, [
    'reference' => $result['orderId'],
    'numbers' => array_map('format_ticket_number', $result['numbers']),
    'total' => count($result['numbers']) * TIERS[$tierId]['price'],
    'emailed' => $emailed,
]);
