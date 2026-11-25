<?php
// Registre des billets (data/tickets.json) + numérotation unique #700, #699, ...
// Toute lecture/écriture se fait sous verrou exclusif (flock) : deux paiements
// simultanés ne peuvent jamais recevoir le même numéro.

const TIERS = [
    'standard' => ['name' => 'Standard', 'price' => 10000],
    'vip' => ['name' => 'Pass VIP', 'price' => 25000],
    'vvip' => ['name' => 'Pass VVIP', 'price' => 50000],
    'gold' => ['name' => 'Pass Gold', 'price' => 100000],
];

function data_dir(array $config): string
{
    // En local (npm run api), les données vont dans .data/ hors de public/,
    // pour ne jamais être copiées dans le build de production.
    $dir = rtrim(getenv('BILLETTERIE_DATA_DIR') ?: $config['data_dir'], '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    // Protection web du dossier des ventes, même si api/.htaccess était supprimé
    if (!file_exists("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
    return $dir;
}

/**
 * Exécute $fn(&$db) sous verrou exclusif puis sauvegarde la base.
 * Retourne ce que retourne $fn.
 */
function with_db(array $config, callable $fn)
{
    $dir = data_dir($config);
    $lock = fopen("$dir/tickets.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Registre des billets indisponible.');
    }
    try {
        $file = "$dir/tickets.json";
        $db = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (!is_array($db)) {
            $db = ['counters' => [], 'orders' => []];
        }
        $before = json_encode($db);
        $result = $fn($db);
        if (json_encode($db) !== $before) {
            $tmp = "$file.tmp";
            file_put_contents($tmp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, $file); // écriture atomique
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function clean_str($v, int $max): string
{
    return mb_substr(trim((string) ($v ?? '')), 0, $max);
}

// ---------------------------------------------------------------------------
// Numérotation par catégorie
//
//   Standard : 001 … 099, 00100 … 00300
//   VIP      : 00301 … 00500
//   VVIP     : 00501 … 00700
//   Gold     : 00701, 00702 … (sans limite : 00900, 00901 …)
//
// Quand la plage d'une catégorie est pleine (Standard, VIP, VVIP), on ajoute
// un « 1 » devant son dernier numéro et on continue : Standard 00300 -> 10300,
// 10301 … Chaque plage de dépassement compte 200 numéros, pour ne jamais
// empiéter sur celle de la catégorie suivante :
//   Standard 10300-10499 · VIP 10500-10699 · VVIP 10700-10899
// Si elle est pleine à son tour, on passe au « 2 » : 20300-20499, etc. (jusqu'à 9).
//
// CAPACITÉ (« max ») : nombre exact de billets disponibles par catégorie.
// Au-delà, la catégorie est complète (en ligne comme sur place) :
//   Standard 497 : 001 → 00300, puis 10300 → 10496
//   VIP      299 : 00301 → 00500, puis 10500 → 10598
//   VVIP     198 : 00501 → 00698
//   Gold     102 : 00701 → 00802

const NUMBERING = [
    'standard' => ['first' => 1, 'last' => 300, 'max' => 497],
    'vip' => ['first' => 301, 'last' => 500, 'max' => 299],
    'vvip' => ['first' => 501, 'last' => 700, 'max' => 198],
    'gold' => ['first' => 701, 'last' => null, 'max' => 102],
];
const OVERFLOW_SIZE = 200;
const GOLD_MAX = 9999; // au-delà, on toucherait les plages de dépassement (10300…)

/** Numéro du billet de rang $index (0 = premier) dans la catégorie $tierId, ou null si la catégorie est complète. */
function ticket_number_at(string $tierId, int $index): ?int
{
    $r = NUMBERING[$tierId];
    if ($index < 0 || $index >= $r['max']) {
        return null; // capacité de la catégorie atteinte
    }
    if ($r['last'] === null) {
        $n = $r['first'] + $index;
        return $n <= GOLD_MAX ? $n : null;
    }
    $size = $r['last'] - $r['first'] + 1;
    if ($index < $size) {
        return $r['first'] + $index;                   // plage normale
    }
    $k = intdiv($index - $size, OVERFLOW_SIZE) + 1;    // 1er, 2e … dépassement
    if ($k > 9) {
        return null;
    }
    return $k * 10000 + $r['last'] + ($index - $size) % OVERFLOW_SIZE;
}

/** Affichage : moins de 100 -> 3 chiffres (#001), sinon 5 chiffres (#00100, #10300). */
function format_ticket_number(int $n): string
{
    return '#' . str_pad((string) $n, $n < 100 ? 3 : 5, '0', STR_PAD_LEFT);
}

/**
 * Choisit $qty numéros libres dans la série de la catégorie (dans sa capacité).
 * Priorité aux numéros jamais attribués ; ensuite, numéros d'anciens billets
 * ANNULÉS (REFUSED) : une annulation libère sa place et son numéro.
 * Retourne la liste des numéros, ou null s'il n'y en a pas assez (+ $available).
 */
function pick_numbers(array $db, string $tierId, int $qty, ?int &$available = null): ?array
{
    $held = [];
    foreach ($db['orders'] as $o) {
        foreach ($o['numbers'] as $n) {
            $held[$n] = true;
        }
    }
    $fresh = [];
    $released = [];
    for ($i = 0; ($n = ticket_number_at($tierId, $i)) !== null; $i++) {
        if (!isset($held[$n])) {
            $fresh[] = $n;
        } elseif (isset($db['refused'][$n])) {
            $released[] = $n;
        }
    }
    $pool = array_merge($fresh, $released);
    $available = count($pool);
    return $available >= $qty ? array_slice($pool, 0, $qty) : null;
}

/**
 * Reprend le numéro d'un billet ANNULÉ pour un nouveau billet : l'annulation est
 * archivée dans l'ancienne commande (« released »), le numéro redevient libre.
 */
function release_refused_number(array &$db, int $n): void
{
    if (!isset($db['refused'][$n])) {
        return;
    }
    foreach ($db['orders'] as $id => $o) {
        $k = array_search($n, $o['numbers'], true);
        if ($k !== false) {
            array_splice($db['orders'][$id]['numbers'], $k, 1);
            $db['orders'][$id]['released'][] = [
                'number' => $n,
                'refusedAt' => $db['refused'][$n]['at'],
                'reason' => $db['refused'][$n]['reason'],
                'paidAt' => $db['paid'][$n]['at'] ?? null,
                'releasedAt' => date('c'),
            ];
            break;
        }
    }
    unset($db['refused'][$n], $db['paid'][$n], $db['checkins'][$n]);
}

/** Prochain numéro qui sera attribué dans une catégorie (pour l'affichage). */
function next_ticket_number(array $db, string $tierId): ?int
{
    return pick_numbers($db, $tierId, 1)[0] ?? null;
}

/** Attribue des numéros à une commande, dans la série de sa catégorie (idempotent). */
function allocate_numbers(array &$db, array $in): array
{
    $id = $in['orderId'];
    if (isset($db['orders'][$id])) {
        return ['ok' => true, 'numbers' => $db['orders'][$id]['numbers'], 'new' => false];
    }
    $tierId = $in['tierId'];
    if (!isset(NUMBERING[$tierId])) {
        return ['ok' => false, 'error' => 'Gamme de billet invalide.'];
    }
    $numbers = pick_numbers($db, $tierId, (int) $in['quantity'], $left);
    if ($numbers === null) { // capacité atteinte : on indique ce qu'il reste
        return ['ok' => false, 'error' => $left === 0
            ? 'Cette catégorie est complète.'
            : "Il ne reste que $left billet(s) dans cette catégorie."];
    }
    foreach ($numbers as $n) {
        release_refused_number($db, $n); // numéro d'un billet annulé : archivé puis réattribué
    }
    $email = clean_str($in['email'] ?? '', 254);
    $db['orders'][$id] = [
        'numbers' => $numbers,
        'tierId' => $tierId,
        'holder' => clean_str($in['holder'] ?? '', 120),
        'phone' => clean_str($in['phone'] ?? '', 30),
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        'transactionId' => clean_str($in['transactionId'] ?? '', 120) ?: null,
        'allocatedAt' => date('c'),
        'emailsSent' => 0,
        'lang' => ($in['lang'] ?? 'fr') === 'en' ? 'en' : 'fr', // langue des emails du client
    ];
    return ['ok' => true, 'numbers' => $numbers, 'new' => true];
}

// ---------------------------------------------------------------------------
// Code de sécurité des QR codes
// Chaque billet a un code dérivé de son numéro et d'une clé secrète propre au
// serveur (data/ticket-secret.key, créée automatiquement). Sans cette clé,
// impossible de fabriquer un QR code valide. À sauvegarder avec tickets.json.

function ticket_secret(array $config): string
{
    $file = data_dir($config) . '/ticket-secret.key';
    if (!is_file($file)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, bin2hex(random_bytes(32)));
        // link() échoue si un autre processus a créé la clé entre-temps : on garde la sienne
        @link($tmp, $file);
        unlink($tmp);
    }
    return trim(file_get_contents($file));
}

// Le code est lié au numéro ET à la commande : si un numéro annulé est réattribué,
// l'ancien QR code (celui du client remboursé) n'est plus accepté.
function ticket_code(array $config, int $number, string $orderId): string
{
    return strtoupper(substr(hash_hmac('sha256', "SEPPO|$number|$orderId", ticket_secret($config)), 0, 10));
}

/** Contenu du QR code : SEPPO-701-1A2B3C4D5E */
function ticket_qr_payload(array $config, int $number, string $orderId): string
{
    return "SEPPO-$number-" . ticket_code($config, $number, $orderId);
}

/**
 * Lit un QR code scanné. Retourne le numéro si le code correspond au titulaire
 * ACTUEL du billet, 'forged' sinon (faux QR, ou billet annulé puis réattribué),
 * null si ce n'est pas un QR de la billetterie.
 */
function parse_qr_payload(array $config, string $payload, array $db)
{
    if (!preg_match('/^SEPPO-(\d{1,6})-([0-9A-F]{10})$/', strtoupper(trim($payload)), $m)) {
        return null;
    }
    $n = (int) $m[1];
    foreach ($db['orders'] as $id => $o) {
        if (in_array($n, $o['numbers'], true)) {
            return hash_equals(ticket_code($config, $n, (string) $id), $m[2]) ? $n : 'forged';
        }
    }
    return 'forged'; // numéro non attribué : aucun QR authentique ne peut exister
}

// ---------------------------------------------------------------------------
// Statuts des billets
//   RESERVED       : réservé en ligne, paiement Maviance pas encore confirmé
//   PAYED          : paiement confirmé par l'organisateur
//   MANUAL         : vendu sur place en espèces par un contrôleur
//   ENTRÉE VALIDÉE : billet contrôlé à l'entrée (définitif)
//   REFUSED        : billet annulé (définitif, ne permet pas d'entrer)
//
// Transitions autorisées :
//   RESERVED -> PAYED (confirmation du paiement, billets PDF envoyés par email)
//   RESERVED -> REFUSED · PAYED -> REFUSED (annulation, avec motif)
//   PAYED ou MANUAL -> ENTRÉE VALIDÉE (contrôle à l'entrée)

const STATUS_RESERVED = 'RESERVED';
const STATUS_REFUSED = 'REFUSED';
const STATUS_PAYED = 'PAYED';
const STATUS_MANUAL = 'MANUAL';
const STATUS_ENTERED = 'ENTRÉE VALIDÉE';

function is_manual(array $order): bool
{
    return ($order['source'] ?? 'online') === 'manual';
}

function is_refused(array $db, int $number): bool
{
    return isset($db['refused'][$number]);
}

function ticket_status(array $db, array $order, int $number): string
{
    if (is_refused($db, $number)) {
        return STATUS_REFUSED;
    }
    if (isset($db['checkins'][$number])) {
        return STATUS_ENTERED;
    }
    if (is_manual($order)) {
        return STATUS_MANUAL;
    }
    // Réservation en ligne : RESERVED tant que l'organisateur n'a pas confirmé le paiement
    if (!empty($order['reservation']) && !isset($db['paid'][$number])) {
        return STATUS_RESERVED;
    }
    return STATUS_PAYED;
}

/** Statut « compté dans les ventes » : payé (en ligne ou espèces) ou déjà entré. */
function is_sold_status(string $status): bool
{
    return in_array($status, [STATUS_PAYED, STATUS_MANUAL, STATUS_ENTERED], true);
}

// ---------------------------------------------------------------------------
// Plages de numéros d'une catégorie (pour la saisie sur place)

/** Le numéro $n appartient-il à la série de la catégorie (plage normale ou dépassements) ? */
function number_in_tier(string $tierId, int $n): bool
{
    $r = NUMBERING[$tierId] ?? null;
    if (!$r || $n < 1) {
        return false;
    }
    $index = tier_index_of($tierId, $n);
    return $index !== null && $index < $r['max']; // dans la capacité de la catégorie
}

/** Rang (0 = premier) du numéro $n dans la série de la catégorie, ou null s'il n'en fait pas partie. */
function tier_index_of(string $tierId, int $n): ?int
{
    $r = NUMBERING[$tierId];
    if ($r['last'] === null) {
        return $n >= $r['first'] && $n <= GOLD_MAX ? $n - $r['first'] : null;
    }
    $size = $r['last'] - $r['first'] + 1;
    if ($n >= $r['first'] && $n <= $r['last']) {
        return $n - $r['first'];
    }
    $k = intdiv($n, 10000);
    $base = $k * 10000 + $r['last'];
    if ($k >= 1 && $k <= 9 && $n >= $base && $n < $base + OVERFLOW_SIZE) {
        return $size + ($k - 1) * OVERFLOW_SIZE + ($n - $base);
    }
    return null;
}

/** Description lisible des numéros possibles, ex. « #001 → #00300, puis #10300 → #10496 ». */
function tier_ranges_label(string $tierId): string
{
    $parts = [];
    $start = $prev = null;
    for ($i = 0; ($n = ticket_number_at($tierId, $i)) !== null; $i++) {
        if ($prev !== null && $n !== $prev + 1) {
            $parts[] = [$start, $prev];
            $start = $n;
        }
        $start ??= $n;
        $prev = $n;
    }
    if ($start !== null) {
        $parts[] = [$start, $prev];
    }
    return implode(', puis ', array_map(fn ($p) => format_ticket_number($p[0]) . ' → ' . format_ticket_number($p[1]), $parts));
}

/** Places restantes d'une catégorie : capacité − billets actifs (les billets annulés libèrent leur place). */
function tier_remaining(array $db, string $tierId): int
{
    $used = 0;
    foreach ($db['orders'] as $o) {
        if ($o['tierId'] === $tierId) {
            foreach ($o['numbers'] as $n) {
                if (!isset($db['refused'][$n])) {
                    $used++;
                }
            }
        }
    }
    return max(0, NUMBERING[$tierId]['max'] - $used);
}

/** Tous les numéros déjà attribués (quel que soit leur statut). */
function used_numbers(array $db): array
{
    $used = [];
    foreach ($db['orders'] as $o) {
        foreach ($o['numbers'] as $n) {
            $used[$n] = true;
        }
    }
    return $used;
}

/** Numéros disponibles d'une catégorie : jamais attribués, puis libérés par une annulation (au plus $limit). */
function free_numbers(array $db, string $tierId, int $limit = 400): array
{
    $available = 0;
    pick_numbers($db, $tierId, 0, $available); // nombre de numéros disponibles
    return array_slice(pick_numbers($db, $tierId, $available) ?? [], 0, $limit);
}

/** « #00301 », « 00301 », « 301 » -> 301 ; sinon null */
function parse_ticket_number($v): ?int
{
    $digits = preg_replace('/\D/', '', (string) $v);
    return ($digits !== '' && strlen($digits) <= 6) ? (int) $digits : null;
}

// ---------------------------------------------------------------------------
// Créations de commandes

function new_order_id(array $db, string $prefix): string
{
    do {
        $id = 'SEP-' . $prefix . substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 3)), 0, 8 - strlen($prefix));
    } while (isset($db['orders'][$id]));
    return $id;
}

/**
 * Vente sur place (espèces) : le contrôleur indique le numéro de chaque billet
 * physique vendu. Chaque numéro doit appartenir à la catégorie et être libre.
 */
function create_manual_order(array &$db, array $in): array
{
    $tierId = $in['tierId'];
    $numbers = [];
    foreach ($in['numbers'] as $raw) {
        $n = parse_ticket_number($raw);
        $label = trim((string) $raw);
        if ($n === null) {
            return ['ok' => false, 'error' => "Numéro de billet invalide : « $label »."];
        }
        if (!number_in_tier($tierId, $n)) {
            $idx = tier_index_of($tierId, $n);
            return ['ok' => false, 'error' => 'Le numéro ' . format_ticket_number($n) . ($idx !== null
                ? ' dépasse la capacité ' . TIERS[$tierId]['name'] . ' (' . NUMBERING[$tierId]['max'] . ' billets : ' . tier_ranges_label($tierId) . ').'
                : ' ne fait pas partie de la plage ' . TIERS[$tierId]['name'] . ' (' . tier_ranges_label($tierId) . ').')];
        }
        if (in_array($n, $numbers, true)) {
            return ['ok' => false, 'error' => 'Le numéro ' . format_ticket_number($n) . ' est saisi deux fois.'];
        }
        $numbers[] = $n;
    }
    $used = used_numbers($db);
    foreach ($numbers as $n) {
        if (isset($used[$n]) && !isset($db['refused'][$n])) { // un billet annulé libère son numéro
            return ['ok' => false, 'error' => 'Le numéro ' . format_ticket_number($n) . ' est déjà attribué à un autre billet.'];
        }
    }
    foreach ($numbers as $n) {
        release_refused_number($db, $n); // numéro d'un billet annulé : archivé puis réattribué
    }
    $id = new_order_id($db, 'M');
    $email = clean_str($in['email'] ?? '', 254);
    $db['orders'][$id] = [
        'numbers' => $numbers,
        'tierId' => $tierId,
        'holder' => clean_str($in['holder'] ?? '', 120),
        'phone' => clean_str($in['phone'] ?? '', 30),
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        'transactionId' => null,
        'allocatedAt' => date('c'),
        'emailsSent' => 0,
        'source' => 'manual',
        'payment' => 'Espèces',
    ];
    return ['ok' => true, 'numbers' => $numbers, 'orderId' => $id];
}

/** Réservation en ligne : numéros attribués automatiquement, statut RESERVED. */
function create_reservation(array &$db, array $in): array
{
    $id = new_order_id($db, 'R');
    $r = allocate_numbers($db, ['orderId' => $id] + $in);
    if ($r['ok']) {
        $db['orders'][$id]['reservation'] = true;
        $r['orderId'] = $id;
    }
    return $r;
}

/**
 * RESERVED -> PAYED pour tous les billets encore réservés d'une commande.
 * Retourne ['ok' => bool, 'numbers' => billets confirmés, 'error' => ...]
 */
function confirm_reservation(array &$db, string $orderId): array
{
    $o = $db['orders'][$orderId] ?? null;
    if (!$o || empty($o['reservation'])) {
        return ['ok' => false, 'error' => 'Réservation introuvable.'];
    }
    $db['paid'] ??= [];
    $confirmed = [];
    foreach ($o['numbers'] as $n) {
        if (ticket_status($db, $o, $n) === STATUS_RESERVED) {
            $db['paid'][$n] = ['at' => date('c')];
            $confirmed[] = $n;
        }
    }
    if (!$confirmed) {
        return ['ok' => false, 'error' => 'Aucun billet de cette réservation n\'est en attente de confirmation.'];
    }
    return ['ok' => true, 'numbers' => $confirmed];
}

/** Billets comptés dans les ventes (payés, espèces, entrés) : [nombre, montant]. */
function active_totals(array $db, ?callable $filter = null): array
{
    $count = 0;
    $amount = 0;
    foreach ($db['orders'] as $o) {
        if ($filter && !$filter($o)) {
            continue;
        }
        foreach ($o['numbers'] as $n) {
            if (is_sold_status(ticket_status($db, $o, $n))) {
                $count++;
                $amount += TIERS[$o['tierId']]['price'] ?? 0;
            }
        }
    }
    return [$count, $amount];
}

/** Billets d'un statut donné : [nombre, montant]. */
function status_totals(array $db, string $status): array
{
    $count = 0;
    $amount = 0;
    foreach ($db['orders'] as $o) {
        foreach ($o['numbers'] as $n) {
            if (ticket_status($db, $o, $n) === $status) {
                $count++;
                $amount += TIERS[$o['tierId']]['price'] ?? 0;
            }
        }
    }
    return [$count, $amount];
}

/**
 * Annule un billet (statut REFUSED, définitif), depuis RESERVED ou PAYED uniquement.
 * Retourne : cancelled | unknown | entered | refused | not_payed | reason_required
 */
function refuse_ticket(array &$db, int $number, string $reason): string
{
    $order = null;
    foreach ($db['orders'] as $o) {
        if (in_array($number, $o['numbers'], true)) {
            $order = $o;
            break;
        }
    }
    if (!$order) {
        return 'unknown';
    }
    $db['refused'] ??= [];
    $status = ticket_status($db, $order, $number);
    if ($status === STATUS_ENTERED) {
        return 'entered';
    }
    if ($status === STATUS_REFUSED) {
        return 'refused';
    }
    if ($status !== STATUS_PAYED && $status !== STATUS_RESERVED) {
        return 'not_payed';
    }
    $reason = mb_substr(trim($reason), 0, 200);
    if (mb_strlen($reason) < 3) {
        return 'reason_required';
    }
    $db['refused'][$number] = ['at' => date('c'), 'reason' => $reason];
    return 'cancelled';
}
