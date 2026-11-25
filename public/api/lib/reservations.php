<?php
// Réservations en ligne : confirmation du paiement par l'organisateur
// (RESERVED -> PAYED) puis envoi des billets PDF par email au client.
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/ticket_pdf.php';
require_once __DIR__ . '/mailer.php';

/**
 * Envoie par email le PDF des billets $numbers d'une commande (paiement en ligne
 * confirmé ou vente sur place). Retourne ['ok' => bool, 'email' => ?, 'error' => ?].
 */
function email_order_tickets(array $config, string $orderId, array $numbers): array
{
    $db = with_db($config, fn (array &$db) => $db);
    $order = $db['orders'][$orderId] ?? null;
    if (!$order || empty($order['email'])) {
        return ['ok' => false, 'email' => null, 'error' => 'Aucune adresse email pour cette commande.'];
    }
    try {
        $mail = build_tickets_email($order['holder'], $order['tierId'], $numbers, (string) ($config['site_url'] ?? ''), false, $orderId, $order['lang'] ?? 'fr');
        send_mail($config, $order['email'], $order['holder'], $mail, [
            ['name' => (($order['lang'] ?? 'fr') === 'en' ? 'tickets' : 'billets') . "-seppo-$orderId.pdf", 'content' => build_tickets_pdf($config, $order, $numbers, $orderId), 'type' => 'application/pdf'],
        ]);
    } catch (Throwable $e) {
        error_log("[billetterie] billets non envoyés ($orderId) : " . $e->getMessage());
        return ['ok' => false, 'email' => $order['email'], 'error' => "L'email n'a pas pu être envoyé. Réessayez dans un instant."];
    }
    with_db($config, function (array &$db) use ($orderId) {
        $db['orders'][$orderId]['emailsSent'] = ($db['orders'][$orderId]['emailsSent'] ?? 0) + 1;
        $db['orders'][$orderId]['ticketsEmailedAt'] = date('c');
    });
    return ['ok' => true, 'email' => $order['email']];
}

/**
 * Confirme une réservation et envoie les billets.
 * Retourne ['ok' => bool, 'numbers' => [...], 'emailed' => bool, 'email' => ?, 'error' => ?, 'mailError' => ?]
 */
function confirm_and_send_tickets(array $config, string $orderId): array
{
    $r = with_db($config, function (array &$db) use ($orderId, $config) {
        $r = confirm_reservation($db, $orderId);
        if ($r['ok']) {
            refresh_sales_file($config, $db);
            $r['order'] = $db['orders'][$orderId];
        }
        return $r;
    });
    if (!$r['ok']) {
        return $r;
    }
    $order = $r['order'];
    unset($r['order']);
    $r['emailed'] = false;
    $r['email'] = $order['email'] ?? null;
    if (!$r['email']) {
        return $r; // pas d'email : le client retire son billet physique
    }
    $m = email_order_tickets($config, $orderId, $r['numbers']);
    $r['emailed'] = $m['ok'];
    if (!$m['ok']) {
        $r['mailError'] = true; // le paiement reste confirmé ; l'envoi peut être relancé
    }
    return $r;
}

/** Renvoie par email les billets PAYED ou MANUAL d'une commande (ex. après un échec d'envoi). */
function resend_tickets(array $config, string $orderId): array
{
    $db = with_db($config, fn (array &$db) => $db);
    $order = $db['orders'][$orderId] ?? null;
    if (!$order || empty($order['email'])) {
        return ['ok' => false, 'error' => 'Aucune adresse email pour cette commande.'];
    }
    $numbers = array_values(array_filter($order['numbers'], fn ($n) => in_array(ticket_status($db, $order, $n), [STATUS_PAYED, STATUS_MANUAL], true)));
    if (!$numbers) {
        return ['ok' => false, 'error' => 'Aucun billet payé (PAYED ou MANUAL) à envoyer.'];
    }
    $m = email_order_tickets($config, $orderId, $numbers);
    return $m['ok'] ? ['ok' => true, 'numbers' => $numbers, 'email' => $m['email']] : ['ok' => false, 'error' => $m['error']];
}
