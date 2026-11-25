<?php
// Espace organisateur – Ventes : https://<domaine>/api/ventes.php
// Résumé des ventes, entrées validées, téléchargement du fichier Excel à jour.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/admin.php';

admin_start();
$error = admin_handle_auth($CONFIG, 'ventes.php');
if (!admin_logged()) {
    admin_login_page($CONFIG, 'Ventes de billets', $error);
}

$db = with_db($CONFIG, fn (array &$db) => $db);

if (isset($_GET['download'])) {
    $file = data_dir($CONFIG) . '/ventes.xlsx';
    write_sales_xlsx($db, $file); // toujours le dernier état
    $name = 'ventes-seppo-' . date('Y-m-d-His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$name\"");
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

$stats = [];
foreach (TIERS as $id => $t) {
    $stats[$id] = ['name' => $t['name'], 'count' => 0, 'amount' => 0];
}
$totalTickets = 0;
$totalAmount = 0;
$lastSale = null;
$byMode = ['PAYED' => ['label' => 'En ligne (PAYED)', 'count' => 0, 'amount' => 0], 'MANUAL' => ['label' => 'Espèces (MANUAL)', 'count' => 0, 'amount' => 0]];
foreach ($db['orders'] as $o) {
    // Seuls les billets vendus comptent (PAYED, MANUAL, entrés) : ni RESERVED ni REFUSED
    $n = count(array_filter($o['numbers'], fn ($num) => is_sold_status(ticket_status($db, $o, $num))));
    $price = TIERS[$o['tierId']]['price'] ?? 0;
    $mode = is_manual($o) ? 'MANUAL' : 'PAYED';
    $byMode[$mode]['count'] += $n;
    $byMode[$mode]['amount'] += $n * $price;
    if (isset($stats[$o['tierId']])) {
        $stats[$o['tierId']]['count'] += $n;
        $stats[$o['tierId']]['amount'] += $n * $price;
    }
    $totalTickets += $n;
    $totalAmount += $n * $price;
    $lastSale = max($lastSale ?? '', $o['allocatedAt']);
}
$entries = count($db['checkins'] ?? []);
[$refusedCount, $refusedAmount] = status_totals($db, STATUS_REFUSED);
[$reservedCount, $reservedAmount] = status_totals($db, STATUS_RESERVED);
// Annulations dont le numéro a été réattribué (historique, détail dans l'onglet « Annulations » de l'Excel)
$releasedCount = array_sum(array_map(fn ($o) => count($o['released'] ?? []), $db['orders']));

admin_page_start('Ventes de billets', 'ventes');
?>
  <div class="card total">
    <div><div class="muted">Billets vendus (payés)</div><div class="big"><?= $totalTickets ?></div></div>
    <div><div class="muted">Montant total</div><div class="big"><?= e(fcfa($totalAmount)) ?></div></div>
    <div><div class="muted">Entrées validées</div><div class="big"><?= $entries ?> / <?= $totalTickets ?></div></div>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>Gamme</th><th class="num">Billets</th><th class="num">Montant</th><th class="num">Restants</th></tr></thead>
      <tbody>
      <?php foreach ($stats as $tid => $s): $left = tier_remaining($db, $tid); ?>
        <tr><td><?= e($s['name']) ?></td><td class="num"><?= $s['count'] ?></td><td class="num"><?= e(fcfa($s['amount'])) ?></td>
            <td class="num"><?= $left > 0 ? $left . ' / ' . NUMBERING[$tid]['max'] : '<strong style="color:#ff8a95">COMPLET</strong>' ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <table style="margin-top:14px">
      <thead><tr><th>Paiement</th><th class="num">Billets</th><th class="num">Montant</th></tr></thead>
      <tbody>
      <?php foreach ($byMode as $m): ?>
        <tr><td><?= e($m['label']) ?></td><td class="num"><?= $m['count'] ?></td><td class="num"><?= e(fcfa($m['amount'])) ?></td></tr>
      <?php endforeach ?>
        <tr><td>Réservés, paiement à confirmer (RESERVED) – non comptés</td><td class="num"><?= $reservedCount ?></td><td class="num"><?= e(fcfa($reservedAmount)) ?></td></tr>
        <tr><td>Annulés (REFUSED) – non comptés</td><td class="num"><?= $refusedCount ?></td><td class="num"><?= e(fcfa($refusedAmount)) ?></td></tr>
        <?php if ($releasedCount): ?>
        <tr><td>Annulations dont la place a été réattribuée (historique)</td><td class="num"><?= $releasedCount ?></td><td class="num">—</td></tr>
        <?php endif ?>
      </tbody>
    </table>
    <p class="muted" style="margin:12px 0 0">
      <?= $lastSale ? 'Dernière vente : ' . e((new DateTime($lastSale))->format('d/m/Y à H:i')) : 'Aucune vente pour le moment.' ?>
      <br>Prochains numéros : <?= e(implode(' · ', array_map(fn ($id) => TIERS[$id]['name'] . ' ' . (($nx = next_ticket_number($db, $id)) !== null ? format_ticket_number($nx) : 'épuisé'), array_keys(TIERS)))) ?>
    </p>
    <a class="btn" href="ventes.php?download=1">Télécharger le fichier Excel</a>
  </div>
<?php
admin_page_end();
