<?php
// Espace organisateur – Liste des billets : https://<domaine>/api/billets.php
// Par défaut : billets valides (tout sauf REFUSED). Filtres par statut et par
// gamme, recherche par numéro, nom, téléphone ou email.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/reservations.php';

admin_start();
$error = admin_handle_auth($CONFIG, 'billets.php');
if (!admin_logged()) {
    admin_login_page($CONFIG, 'Billets', $error);
}

// ---- Confirmation du paiement d'une réservation (RESERVED -> PAYED + envoi des billets)
if (($_POST['action'] ?? '') === 'confirm') {
    $n = (int) preg_replace('/\D/', '', (string) ($_POST['number'] ?? ''));
    $t = find_ticket(with_db($CONFIG, fn (array &$db) => $db), $n);
    $r = $t ? confirm_and_send_tickets($CONFIG, $t[0]) : ['ok' => false, 'error' => 'Billet introuvable.'];
    $_SESSION['list_flash'] = ['n' => $n, 'result' => !$r['ok'] ? 'confirm_failed' : (!empty($r['mailError']) ? 'confirmed_mailerror' : ($r['emailed'] ? 'confirmed_mailed' : 'confirmed_nomail')),
        'count' => count($r['numbers'] ?? []), 'email' => $r['email'] ?? null, 'error' => $r['error'] ?? null];
    $back = (string) ($_POST['back'] ?? '');
    header('Location: billets.php' . (str_starts_with($back, '?') ? $back : ''));
    exit;
}

// ---- Annulation d'un billet RESERVED ou PAYED depuis la liste
if (($_POST['action'] ?? '') === 'refuse') {
    $n = (int) preg_replace('/\D/', '', (string) ($_POST['number'] ?? ''));
    $reason = (string) ($_POST['reason'] ?? '');
    $result = with_db($CONFIG, function (array &$db) use ($n, $reason, $CONFIG) {
        $r = refuse_ticket($db, $n, $reason); // seuls RESERVED et PAYED -> REFUSED sont autorisés
        if ($r === 'cancelled') {
            refresh_sales_file($CONFIG, $db);
        }
        return $r;
    });
    $_SESSION['list_flash'] = ['n' => $n, 'result' => $result];
    $back = (string) ($_POST['back'] ?? '');
    header('Location: billets.php' . (str_starts_with($back, '?') ? $back : ''));
    exit;
}
$listFlash = $_SESSION['list_flash'] ?? null;
unset($_SESSION['list_flash']);

$db = with_db($CONFIG, fn (array &$db) => $db);

// ---- Toutes les lignes (une par billet)
$tickets = [];
foreach ($db['orders'] as $orderId => $o) {
    foreach ($o['numbers'] as $n) {
        $tickets[] = [
            'number' => $n,
            'holder' => $o['holder'],
            'phone' => $o['phone'] ?? '',
            'email' => $o['email'] ?? '',
            'tierId' => $o['tierId'],
            'tier' => TIERS[$o['tierId']]['name'] ?? $o['tierId'],
            'payment' => is_manual($o) ? 'Espèces' : 'En ligne',
            'status' => ticket_status($db, $o, $n),
            'date' => $o['allocatedAt'],
            'enteredAt' => $db['checkins'][$n]['at'] ?? null,
            'orderId' => $orderId,
        ];
    }
}
// Ventes les plus récentes d'abord, puis par numéro
usort($tickets, fn ($a, $b) => [$b['date'], $a['number']] <=> [$a['date'], $b['number']]);

// ---- Filtres
$FILTERS = [
    'valides' => ['label' => 'Valides', 'match' => fn ($t) => is_sold_status($t['status'])], // payés, espèces, entrés
    'reserved' => ['label' => 'RESERVED', 'match' => fn ($t) => $t['status'] === STATUS_RESERVED],
    'payed' => ['label' => 'PAYED', 'match' => fn ($t) => $t['status'] === STATUS_PAYED],
    'manual' => ['label' => 'MANUAL', 'match' => fn ($t) => $t['status'] === STATUS_MANUAL],
    'entered' => ['label' => 'Entrée validée', 'match' => fn ($t) => $t['status'] === STATUS_ENTERED],
    'refused' => ['label' => 'REFUSED', 'match' => fn ($t) => $t['status'] === STATUS_REFUSED],
    'all' => ['label' => 'Tous', 'match' => fn ($t) => true],
];
$getStr = fn ($k) => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
$filter = isset($FILTERS[$getStr('statut')]) ? $getStr('statut') : 'valides';
$tier = isset(TIERS[$getStr('gamme')]) ? $getStr('gamme') : '';
$q = mb_substr(trim($getStr('q')), 0, 60);

$counts = [];
foreach ($FILTERS as $key => $f) {
    $counts[$key] = count(array_filter($tickets, $f['match']));
}

$norm = fn ($s) => mb_strtolower(preg_replace('/\s+/', '', (string) $s));
$list = array_values(array_filter($tickets, function ($t) use ($FILTERS, $filter, $tier, $q, $norm) {
    if (!($FILTERS[$filter]['match'])($t) || ($tier !== '' && $t['tierId'] !== $tier)) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    $needle = $norm(ltrim($q, '#'));
    // « #00301 » ou 1 à 5 chiffres = numéro de billet ; 6 chiffres et plus = téléphone
    if (ctype_digit($needle) && (str_starts_with($q, '#') || strlen($needle) <= 5)) {
        return (string) $t['number'] === ltrim($needle, '0');
    }
    if (ctype_digit($needle)) {
        return str_contains(preg_replace('/\D/', '', $t['phone']), $needle);
    }
    return str_contains($norm($t['holder']), $needle) || str_contains($norm($t['email']), $needle);
}));
$listAmount = array_sum(array_map(fn ($t) => TIERS[$t['tierId']]['price'] ?? 0, $list));

// Lien qui conserve les filtres en cours et en remplace certains
$url = fn (array $p) => 'billets.php?' . http_build_query(array_filter(
    array_merge(['statut' => $filter, 'gamme' => $tier, 'q' => $q], $p),
    fn ($v) => $v !== ''
));

// Billets RESERVED de chaque commande (message de confirmation)
$pendingByOrder = [];
foreach ($tickets as $t) {
    if ($t['status'] === STATUS_RESERVED) {
        $pendingByOrder[$t['orderId']][] = $t['number'];
    }
}
$confirmMsg = function (array $t) use ($pendingByOrder, $db) {
    $nums = $pendingByOrder[$t['orderId']] ?? [];
    $o = $db['orders'][$t['orderId']];
    return 'Confirmer le paiement Maviance de ' . count($nums) . ' billet(s) (' . implode(', ', array_map('format_ticket_number', $nums)) . ') pour '
        . fcfa(count($nums) * (TIERS[$o['tierId']]['price'] ?? 0)) . ' – ' . $o['holder'] . " ?\n\nLes billets passeront à PAYED"
        . ($o['email'] ? ' et seront envoyés par email à ' . $o['email'] . '.' : " (pas d'email : remettre les billets physiques).");
};
// Boutons d'action d'un billet (RESERVED : confirmer / annuler ; PAYED : annuler)
$actions = function (array $t, bool $card) use ($confirmMsg, $url) {
    $out = '';
    if ($t['status'] === STATUS_RESERVED) {
        $out .= '<form method="post" class="inline-form" onclick="event.stopPropagation()" onsubmit="return confirm(' . e(json_encode($confirmMsg($t), JSON_UNESCAPED_UNICODE)) . ')">'
            . '<input type="hidden" name="action" value="confirm"><input type="hidden" name="number" value="' . $t['number'] . '">'
            . '<input type="hidden" name="back" value="' . e('?' . parse_url($url([]), PHP_URL_QUERY)) . '">'
            . '<button class="btn-mini btn-mini-ok" type="submit">' . ($card ? 'Confirmer le paiement' : 'Confirmer') . '</button></form>';
    }
    if ($t['status'] === STATUS_RESERVED || $t['status'] === STATUS_PAYED) {
        $out .= '<button type="button" class="btn-mini js-cancel" data-n="' . $t['number'] . '" data-label="' . e(format_ticket_number($t['number'])) . '" data-holder="' . e($t['holder'])
            . '" data-tier="' . e($t['tier']) . '" data-status="' . e($t['status']) . '">' . ($card ? 'Annuler ce billet' : 'Annuler') . '</button>';
    }
    return $out;
};

$fmt = fn ($iso) => (new DateTime($iso))->format('d/m H:i');

admin_page_start('Billets', 'billets');
?>
<style>
  main { max-width:960px; }
  .chips { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 12px; }
  .chips a { padding:7px 12px; border-radius:999px; border:1px solid var(--line); color:var(--muted); text-decoration:none; font-size:14px; font-weight:600; white-space:nowrap; }
  .chips a.on { background:var(--gold); border-color:var(--gold); color:#1a0d06; }
  .chips b { font-weight:800; margin-left:4px; }
  .filters { display:grid; grid-template-columns:1fr 180px auto; gap:8px; margin:0 0 12px; }
  .filters select, .filters input { width:100%; padding:11px 12px; border-radius:8px; border:1px solid #6b4a1c; background:#120804; color:var(--text); font-size:16px; }
  .filters .btn { margin:0; width:auto; padding:0 18px; }
  .summary { margin:0 0 10px; color:var(--muted); font-size:14px; }
  .summary strong { color:var(--text); }
  table.list { width:100%; border-collapse:collapse; font-size:14px; }
  table.list th, table.list td { padding:10px 8px; border-bottom:1px solid #3a2410; text-align:left; vertical-align:middle; }
  table.list th:first-child, table.list td:first-child { width:auto; }
  table.list tr.row { cursor:pointer; }
  table.list tr.row:hover td { background:#2a1408; }
  .n { font-weight:900; font-size:16px; white-space:nowrap; }
  .n a { color:var(--text); text-decoration:none; }
  .sub2 { display:block; color:var(--muted); font-size:12px; }
  .empty { text-align:center; color:var(--muted); padding:28px 10px; }
  .btn-mini { padding:7px 12px; border-radius:6px; border:1px solid var(--ko); background:transparent; color:#ff8a95; font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; }
  .btn-mini:hover, .btn-mini:focus-visible { background:var(--ko); color:#fff; outline:none; }
  .btn-mini-ok { border-color:var(--ok); color:#7ee29a; }
  .btn-mini-ok:hover, .btn-mini-ok:focus-visible { background:var(--ok); color:#fff; }
  .inline-form { display:inline; margin:0; }
  td.actions { white-space:nowrap; }
  td.actions > * + * { margin-left:6px; }
  .flash { margin:0 0 12px; padding:10px 12px; border-radius:8px; font-size:14px; }
  .flash.ok { background:var(--ok-bg); border:1px solid var(--ok); }
  .flash.ko { background:var(--ko-bg); border:1px solid var(--ko); }
  dialog { width:min(440px, calc(100vw - 32px)); padding:20px; border:1px solid var(--ko); border-radius:12px; background:var(--card); color:var(--text); }
  dialog::backdrop { background:rgba(0,0,0,.7); }
  dialog h2 { margin:0 0 6px; font-size:22px; color:#ff6b78; }
  dialog p { margin:0 0 10px; }
  dialog .row2 { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:14px; }
  dialog .row2 .btn { margin:0; }
  .cards { display:none; }
  @media (max-width:900px) { /* tablette et téléphone : cartes (le tableau ne tient pas) */
    .filters { grid-template-columns:1fr 1fr; }
    .filters input { grid-column:1 / -1; }
    .filters .btn { width:100%; padding:12px; }
    .table-wrap { display:none; }
    .cards { display:grid; gap:8px; }
    /* Carte : n° à gauche ; nom, statut, détails empilés à droite (aucun débordement à 320px) */
    .tk { display:grid; grid-template-columns:auto minmax(0, 1fr); gap:4px 12px; align-items:center; padding:12px; border:1px solid var(--line); border-radius:10px; background:var(--card); }
    .tk-link { display:contents; color:var(--text); text-decoration:none; }
    .tk .n { font-size:20px; grid-row:1 / span 3; align-self:center; }
    .tk .who { overflow-wrap:anywhere; }
    .tk .status { justify-self:start; }
    .tk-actions { grid-column:1 / -1; display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:6px; margin-top:6px; }
    .tk-actions .inline-form { display:block; }
    .tk-actions .btn-mini { width:100%; padding:10px; }
    .tk .who { font-weight:700; text-transform:uppercase; font-size:14px; }
    .tk .meta { color:var(--muted); font-size:12px; overflow-wrap:anywhere; }
  }
</style>

  <div class="chips" role="tablist" aria-label="Filtrer par statut">
    <?php foreach ($FILTERS as $key => $f): ?>
      <a href="<?= e($url(['statut' => $key])) ?>" class="<?= $filter === $key ? 'on' : '' ?>"><?= e($f['label']) ?><b><?= $counts[$key] ?></b></a>
    <?php endforeach ?>
  </div>

  <form class="filters" method="get" role="search">
    <input type="hidden" name="statut" value="<?= e($filter) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="N° (ex : 001), nom, téléphone ou email" aria-label="Rechercher">
    <select name="gamme" aria-label="Gamme" onchange="this.form.submit()">
      <option value="">Toutes gammes</option>
      <?php foreach (TIERS as $id => $t): ?><option value="<?= e($id) ?>" <?= $tier === $id ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
    </select>
    <button class="btn" type="submit">Rechercher</button>
  </form>

  <p class="summary"><strong><?= count($list) ?></strong> billet(s) · <?= e(fcfa($listAmount)) ?>
    <?php if ($q !== '' || $tier !== ''): ?> · <a href="<?= e('billets.php?statut=' . $filter) ?>" style="color:var(--muted)">effacer la recherche</a><?php endif ?>
  </p>

<?php if ($listFlash):
    $msg = [
        'cancelled' => ['ok', 'Billet #%d annulé : statut REFUSED.'],
        'reason_required' => ['ko', 'Billet #%d non annulé : indiquez un motif (3 caractères minimum).'],
        'entered' => ['ko', 'Billet #%d non annulé : son entrée est déjà validée (statut définitif).'],
        'refused' => ['ko', 'Le billet #%d était déjà annulé.'],
        'not_payed' => ['ko', 'Billet #%d non annulé : seul un billet RESERVED ou PAYED peut passer à REFUSED.'],
        'confirmed_mailed' => ['ok', 'Paiement confirmé (#%d) : ' . ($listFlash['count'] ?? 0) . ' billet(s) PAYED, envoyé(s) par email à ' . ($listFlash['email'] ?? '') . '.'],
        'confirmed_nomail' => ['ok', 'Paiement confirmé (#%d) : ' . ($listFlash['count'] ?? 0) . " billet(s) PAYED. Pas d'email : remettez le(s) billet(s) physique(s)."],
        'confirmed_mailerror' => ['ko', "Paiement confirmé (#%d), mais l'email n'a pas pu être envoyé : renvoyez les billets depuis la page Contrôle."],
        'confirm_failed' => ['ko', 'Confirmation impossible (#%d) : ' . ($listFlash['error'] ?? '')],
        'unknown' => ['ko', 'Billet #%d introuvable.'],
    ][$listFlash['result']] ?? ['ko', 'Action impossible sur le billet #%d.']; ?>
  <p class="flash <?= $msg[0] ?>" role="status"><?= e(sprintf(str_replace('#%d', '%s', $msg[1]), format_ticket_number((int) $listFlash['n']))) ?></p>
<?php endif ?>

<?php if (!$list): ?>
  <div class="card empty">Aucun billet ne correspond.</div>
<?php else: ?>
  <div class="card table-wrap" style="padding:6px 10px">
    <table class="list">
      <thead><tr><th>N°</th><th>Titulaire</th><th>Gamme</th><th>Statut</th><th>Paiement</th><th>Vendu le</th><th><span class="visually-hidden">Action</span></th></tr></thead>
      <tbody>
      <?php foreach ($list as $t): $href = 'controle.php?n=' . $t['number']; ?>
        <tr class="row" onclick="location.href='<?= e($href) ?>'">
          <td class="n"><a href="<?= e($href) ?>"><?= e(format_ticket_number($t['number'])) ?></a></td>
          <td><?= e($t['holder']) ?><span class="sub2"><?= e(trim($t['phone'] . ($t['email'] ? ' · ' . $t['email'] : ''), ' ·')) ?></span></td>
          <td><?= e($t['tier']) ?></td>
          <td><span class="status <?= status_class($t['status']) ?>"><?= e($t['status']) ?></span><?php if ($t['enteredAt']): ?><span class="sub2">à <?= e($fmt($t['enteredAt'])) ?></span><?php endif ?></td>
          <td><?= e($t['payment']) ?></td>
          <td><?= e($fmt($t['date'])) ?></td>
          <td class="actions" onclick="event.stopPropagation()"><?= $actions($t, false) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <div class="cards">
    <?php foreach ($list as $t): ?>
      <div class="tk">
        <a class="tk-link" href="controle.php?n=<?= $t['number'] ?>">
          <span class="n"><?= e(format_ticket_number($t['number'])) ?></span>
          <span class="who"><?= e($t['holder']) ?></span>
          <span class="status <?= status_class($t['status']) ?>"><?= e($t['status']) ?></span>
          <span class="meta"><?= e($t['tier']) ?> · <?= e($t['payment']) ?><?= $t['phone'] ? ' · ' . e($t['phone']) : '' ?><?= $t['enteredAt'] ? ' · entré à ' . e($fmt($t['enteredAt'])) : '' ?></span>
        </a>
        <?php if ($a = $actions($t, true)): ?><div class="tk-actions"><?= $a ?></div><?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
<?php endif ?>

<dialog id="cancel-dlg" aria-labelledby="cancel-title">
  <form method="post">
    <input type="hidden" name="action" value="refuse">
    <input type="hidden" name="number" id="cancel-number">
    <input type="hidden" name="back" value="<?= e('?' . parse_url($url([]), PHP_URL_QUERY)) ?>">
    <h2 id="cancel-title">Annuler le billet <span id="cancel-num"></span> ?</h2>
    <p id="cancel-who" class="muted"></p>
    <p>Le billet passera de <strong id="cancel-from">PAYED</strong> à <strong>REFUSED</strong> et ne permettra plus d'entrer. <strong>Action irréversible.</strong></p>
    <label for="cancel-reason">Motif de l'annulation *</label>
    <input id="cancel-reason" name="reason" type="text" required minlength="3" maxlength="200" placeholder="Ex : remboursé, billet perdu, fraude…">
    <div class="row2">
      <button type="button" class="btn btn-ghost" id="cancel-close">Fermer</button>
      <button type="submit" class="btn btn-danger">Annuler le billet</button>
    </div>
  </form>
</dialog>
<script>
(() => {
  const dlg = document.getElementById('cancel-dlg')
  document.querySelectorAll('.js-cancel').forEach((b) => b.addEventListener('click', (e) => {
    e.stopPropagation() // ne pas ouvrir la page de contrôle (clic sur la ligne)
    if (!dlg.showModal) { location.href = 'controle.php?n=' + b.dataset.n; return } // navigateur ancien
    document.getElementById('cancel-number').value = b.dataset.n
    document.getElementById('cancel-num').textContent = b.dataset.label
    document.getElementById('cancel-who').textContent = b.dataset.holder + ' · ' + b.dataset.tier
    document.getElementById('cancel-from').textContent = b.dataset.status
    document.getElementById('cancel-reason').value = ''
    dlg.showModal()
    document.getElementById('cancel-reason').focus()
  }))
  document.getElementById('cancel-close').addEventListener('click', () => dlg.close())
})()
</script>
<?php
admin_page_end();
