<?php
// Espace organisateur – Vente sur place (espèces) : https://<domaine>/api/vente-sur-place.php
// Le contrôleur enregistre un billet payé en espèces : il reçoit un numéro de la
// série commune (#695, #694, ...) avec le statut MANUAL. À l'entrée, ce billet
// se contrôle par son numéro sur controle.php.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/reservations.php';

admin_start();
$error = admin_handle_auth($CONFIG, 'vente-sur-place.php');
if (!admin_logged()) {
    admin_login_page($CONFIG, 'Vente sur place', $error);
}

$formError = '';
$old = ['name' => '', 'phone' => '', 'email' => '', 'tier' => 'standard', 'qty' => 1, 'numbers' => ['']];

if (($_POST['action'] ?? '') === 'resend') {
    $key = (string) ($_POST['sale'] ?? '');
    if (isset($_SESSION['sales'][$key])) {
        $sale = $_SESSION['sales'][$key];
        $_SESSION['sales'][$key]['mail'] = resend_tickets($CONFIG, $sale['orderId'])['ok'] ? 'sent' : 'failed';
    }
    header('Location: vente-sur-place.php?ok=' . urlencode($key));
    exit;
}

if (($_POST['action'] ?? '') === 'sell') {
    $old = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'tier' => (string) ($_POST['tier'] ?? ''),
        'qty' => (int) ($_POST['qty'] ?? 0),
        // Numéro de chaque billet physique vendu (saisi ou choisi dans la liste)
        'numbers' => array_values(array_map(fn ($v) => trim((string) $v), is_array($_POST['numbers'] ?? null) ? $_POST['numbers'] : [])),
    ];
    $nonce = (string) ($_POST['nonce'] ?? '');

    // Formulaire déjà traité (double appui, rechargement) : on réaffiche le même résultat
    if ($nonce !== '' && isset($_SESSION['sales'][$nonce])) {
        header('Location: vente-sur-place.php?ok=' . urlencode($nonce));
        exit;
    }
    if (mb_strlen($old['name']) < 2) {
        $formError = 'Indiquez le nom du client.';
    } elseif ($old['phone'] === '') {
        $formError = 'Indiquez le numéro de téléphone du client.';
    } elseif (!preg_match('/^\+?[0-9][0-9 .\-]{8,18}$/', $old['phone']) || strlen(preg_replace('/\D/', '', $old['phone'])) < 9) {
        $formError = 'Numéro de téléphone invalide (9 chiffres minimum, ex : 690 00 00 00 ou +237 690 00 00 00).';
    } elseif (!isset(TIERS[$old['tier']])) {
        $formError = 'Choisissez une gamme de billet.';
    } elseif ($old['qty'] < 1 || $old['qty'] > (int) $CONFIG['max_tickets_per_order']) {
        $formError = 'Nombre de billets invalide (1 à ' . (int) $CONFIG['max_tickets_per_order'] . ').';
    } elseif (count($old['numbers']) !== $old['qty'] || in_array('', $old['numbers'], true)) {
        $formError = 'Indiquez le numéro de chaque billet (' . $old['qty'] . ' numéro(s) attendu(s)).';
    } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $formError = 'Adresse email invalide (ou laissez vide).';
    } elseif ($nonce === '' || !isset($_SESSION['nonces'][$nonce])) {
        $formError = 'Formulaire expiré, merci de recommencer.';
    } else {
        $r = with_db($CONFIG, function (array &$db) use ($old, $CONFIG) {
            $r = create_manual_order($db, [
                'numbers' => $old['numbers'], 'tierId' => $old['tier'], 'holder' => $old['name'],
                'phone' => $old['phone'], 'email' => $old['email'],
            ]);
            if ($r['ok']) {
                refresh_sales_file($CONFIG, $db);
            }
            return $r;
        });
        if ($r['ok']) {
            unset($_SESSION['nonces'][$nonce]);
            // Email renseigné : les billets PDF (avec QR code) sont envoyés automatiquement
            $mail = 'none';
            if ($old['email'] !== '') {
                $mail = email_order_tickets($CONFIG, $r['orderId'], $r['numbers'])['ok'] ? 'sent' : 'failed';
            }
            $_SESSION['sales'][$nonce] = ['orderId' => $r['orderId'], 'numbers' => $r['numbers'], 'mail' => $mail];
            $_SESSION['sales'] = array_slice($_SESSION['sales'], -20, null, true);
            header('Location: vente-sur-place.php?ok=' . urlencode($nonce));
            exit;
        }
        $formError = $r['error'];
    }
}

// Jeton à usage unique pour ce formulaire
$newNonce = bin2hex(random_bytes(12));
$_SESSION['nonces'][$newNonce] = true;
$_SESSION['nonces'] = array_slice($_SESSION['nonces'], -20, null, true);

$done = isset($_GET['ok']) ? ($_SESSION['sales'][(string) $_GET['ok']] ?? null) : null;
$db = with_db($CONFIG, fn (array &$db) => $db);
$doneOrder = $done ? ($db['orders'][$done['orderId']] ?? null) : null;

[$cashCount, $cashAmount] = active_totals($db, 'is_manual'); // hors billets annulés
// Numéros encore libres de chaque catégorie (suggestions du champ « Numéro du billet »)
$freeByTier = [];
foreach (array_keys(TIERS) as $id) {
    $freeByTier[$id] = array_map('format_ticket_number', free_numbers($db, $id, 600));
}
// Numéros possibles et places restantes de chaque catégorie (capacités fixées dans lib/store.php)
$ranges = [];
$remaining = [];
foreach (array_keys(TIERS) as $id) {
    $ranges[$id] = tier_ranges_label($id) . ' · capacité ' . NUMBERING[$id]['max'];
    $remaining[$id] = tier_remaining($db, $id);
}
$formNumbers = $done ? [''] : ($old['numbers'] ?: array_fill(0, max(1, (int) $old['qty']), ''));
$cash = ['count' => $cashCount, 'amount' => $cashAmount];

admin_page_start('Vente sur place', 'manuel');
?>
<style>
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .grid .full { grid-column:1 / -1; }
  select, input[type=number], input[type=tel], input[type=email] { width:100%; padding:12px; border-radius:8px; border:1px solid #6b4a1c; background:#120804; color:var(--text); font-size:16px; }
  .tiers { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .tiers label { margin:0; display:flex; align-items:center; gap:8px; padding:12px; border:1px solid #6b4a1c; border-radius:8px; color:var(--text); font-size:15px; cursor:pointer; }
  .tiers input { accent-color:var(--gold); width:18px; height:18px; }
  .tiers label:has(input:checked) { border-color:var(--gold); background:#2a1408; }
  .tiers small { display:block; color:var(--muted); font-size:12px; }
  .numbers { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:8px; margin-bottom:6px; }
  .numfield { display:grid; gap:4px; margin:0; font-size:13px; color:var(--muted); }
  .numbers input { width:100%; padding:12px; border-radius:8px; border:1px solid #6b4a1c; background:#120804; color:var(--text); font-size:16px; font-weight:700; letter-spacing:.04em; }
  .total-line { display:flex; justify-content:space-between; align-items:baseline; margin-top:14px; padding-top:12px; border-top:1px dashed var(--line); }
  .done { border:2px solid var(--ok); background:var(--ok-bg); border-radius:12px; padding:20px; margin-bottom:16px; text-align:center; }
  .done h2 { margin:0 0 8px; color:#5fe07f; font-size:24px; }
  .nums { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; margin:10px 0; }
  .nums a { display:inline-block; padding:8px 14px; background:#fff; color:#000; font-weight:900; font-size:26px; border-radius:6px; text-decoration:none; }
  .badge { display:inline-block; padding:3px 10px; border-radius:4px; font-weight:800; font-size:13px; letter-spacing:.06em; background:#ff9f1c; color:#1a0d06; }
  @media (max-width:420px) { .grid, .tiers { grid-template-columns:1fr; } }
</style>

<?php if ($done && $doneOrder): ?>
  <div class="done" role="status">
    <h2>✅ <?= count($done['numbers']) > 1 ? count($done['numbers']) . ' billets enregistrés' : 'Billet enregistré' ?></h2>
    <p><strong><?= e($doneOrder['holder']) ?></strong> · <?= e(TIERS[$doneOrder['tierId']]['name']) ?> · <span class="badge">MANUAL</span></p>
    <div class="nums">
      <?php foreach ($done['numbers'] as $n): ?><a href="controle.php?n=<?= $n ?>" title="Contrôler ce billet"><?= e(format_ticket_number($n)) ?></a><?php endforeach ?>
    </div>
    <p>Encaissé : <strong><?= e(fcfa(count($done['numbers']) * TIERS[$doneOrder['tierId']]['price'])) ?></strong> en espèces</p>
    <?php if (($done['mail'] ?? 'none') === 'sent'): ?>
    <p>✉ Billets envoyés par email à <strong><?= e($doneOrder['email']) ?></strong>.</p>
    <?php elseif (($done['mail'] ?? 'none') === 'failed'): ?>
    <p class="error">L'email à <?= e($doneOrder['email']) ?> n'a pas pu être envoyé.</p>
    <form method="post">
      <input type="hidden" name="action" value="resend">
      <input type="hidden" name="sale" value="<?= e((string) $_GET['ok']) ?>">
      <button class="btn btn-ghost" type="submit">✉ Réessayer l'envoi des billets</button>
    </form>
    <?php endif ?>
    <p class="muted">Remettez <?= count($done['numbers']) > 1 ? 'les billets physiques portant ces numéros' : 'le billet physique portant ce numéro' ?> au client. Touchez un numéro pour valider l'entrée tout de suite.</p>
  </div>
<?php endif ?>

  <form class="card" method="post" autocomplete="off">
    <input type="hidden" name="action" value="sell">
    <input type="hidden" name="nonce" value="<?= e($newNonce) ?>">
    <div class="grid">
      <div class="full">
        <label for="name">Nom du client *</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= e($done ? '' : $old['name']) ?>">
      </div>
      <div>
        <label for="phone">Téléphone *</label>
        <input id="phone" name="phone" type="tel" inputmode="tel" maxlength="30" required minlength="9" pattern="\+?[0-9][0-9 .\-]{8,18}" title="9 chiffres minimum, ex : 690 00 00 00 ou +237 690 00 00 00" placeholder="+237 6XX XXX XXX" value="<?= e($done ? '' : $old['phone']) ?>">
      </div>
      <div>
        <label for="email">Email du client (facultatif : ses billets lui seront envoyés)</label>
        <input id="email" name="email" type="email" maxlength="254" value="<?= e($done ? '' : $old['email']) ?>">
      </div>
      <div class="full">
        <label>Gamme *</label>
        <div class="tiers">
          <?php foreach (TIERS as $id => $t): ?>
          <label><input type="radio" name="tier" value="<?= e($id) ?>" data-price="<?= $t['price'] ?>" <?= ($done ? 'standard' : $old['tier']) === $id ? 'checked' : '' ?>>
            <span><?= e($t['name']) ?><small><?= e(fcfa($t['price'])) ?> · <?= $remaining[$id] > 0 ? $remaining[$id] . ' restant' . ($remaining[$id] > 1 ? 's' : '') : '<strong style="color:#ff8a95">COMPLET</strong>' ?></small></span></label>
          <?php endforeach ?>
        </div>
      </div>
      <div class="full">
        <label for="qty">Nombre de billets *</label>
        <input id="qty" name="qty" type="number" min="1" max="<?= (int) $CONFIG['max_tickets_per_order'] ?>" value="<?= count($formNumbers) ?>" required>
      </div>
      <div class="full">
        <label>Numéro de chaque billet * <small class="muted" id="range-hint">Plage <?= e($ranges[$done ? 'standard' : ($old['tier'] ?: 'standard')] ?? '') ?></small></label>
        <div id="numbers" class="numbers">
          <?php foreach ($formNumbers as $i => $v): ?>
          <label class="numfield"><span>Billet <?= $i + 1 ?></span>
            <input name="numbers[]" type="text" inputmode="numeric" required autocomplete="off" list="free-<?= e($done ? 'standard' : ($old['tier'] ?: 'standard')) ?>"
                   placeholder="ex : 001" value="<?= e($v) ?>">
          </label>
          <?php endforeach ?>
        </div>
        <small class="muted">Un numéro par billet vendu : tapez le numéro inscrit sur chaque billet physique, ou choisissez-le dans la liste des numéros libres.</small>
        <?php foreach ($freeByTier as $id => $nums): ?>
        <datalist id="free-<?= e($id) ?>"><?php foreach ($nums as $x): ?><option value="<?= e($x) ?>"></option><?php endforeach ?></datalist>
        <?php endforeach ?>
      </div>
    </div>
    <div class="total-line"><span>À encaisser en espèces</span><strong class="big" id="total">—</strong></div>
    <?php if ($formError): ?><p class="error" role="alert"><?= e($formError) ?></p><?php endif ?>
    <button class="btn" type="submit" id="submit">Enregistrer le paiement en espèces</button>
  </form>

  <p class="muted" style="text-align:center">Ventes en espèces enregistrées : <strong style="color:#fff6ea"><?= $cash['count'] ?></strong> billet(s) · <?= e(fcfa($cash['amount'])) ?></p>

<script>
(() => {
  const form = document.querySelector('form.card'), qty = document.getElementById('qty'), total = document.getElementById('total')
  const fmt = (v) => new Intl.NumberFormat('fr-FR').format(v).replace(/[  ]/g, '.') + ' FCFA'
  const update = () => {
    const t = form.querySelector('input[name=tier]:checked')
    const q = Math.max(0, parseInt(qty.value, 10) || 0)
    total.textContent = t ? fmt(t.dataset.price * q) : '—'
  }
  // Un champ « numéro » par billet ; liste des numéros libres selon la catégorie
  const box = document.getElementById('numbers'), hint = document.getElementById('range-hint')
  const ranges = <?= json_encode($ranges, JSON_UNESCAPED_UNICODE) ?>; // « ; » indispensable : PHP supprime le retour à la ligne après « ?> »
  const tierId = () => form.querySelector('input[name=tier]:checked')?.value || 'standard'
  const syncNumbers = () => {
    const want = Math.min(<?= (int) $CONFIG['max_tickets_per_order'] ?>, Math.max(1, parseInt(qty.value, 10) || 1))
    while (box.children.length < want) {
      const f = box.firstElementChild.cloneNode(true)
      f.querySelector('input').value = ''
      box.appendChild(f)
    }
    while (box.children.length > want) box.lastElementChild.remove()
    ;[...box.children].forEach((f, k) => {
      f.querySelector('span').textContent = 'Billet ' + (k + 1)
      f.querySelector('input').setAttribute('list', 'free-' + tierId())
    })
    hint.textContent = 'Plage ' + (ranges[tierId()] || '')
  }
  form.addEventListener('input', (e) => { if (e.target === qty || e.target.name === 'tier') syncNumbers(); update() })
  form.addEventListener('change', (e) => { if (e.target.name === 'tier') { syncNumbers(); update() } })
  syncNumbers()
  update()
  // Confirmation + un seul envoi (évite les doublons sur double appui)
  form.addEventListener('submit', (e) => {
    const t = form.querySelector('input[name=tier]:checked')
    const label = t ? t.closest('label').querySelector('span').firstChild.textContent.trim() : ''
    const nums = [...box.querySelectorAll('input')].map((i) => i.value.trim()).join(', ')
    if (!confirm(`Confirmer : ${qty.value} billet(s) ${label} (${nums}) pour ${form.elements.name.value} (${form.elements.phone.value})\nMontant reçu en espèces : ${total.textContent}`)) {
      e.preventDefault(); return
    }
    document.getElementById('submit').disabled = true
  })
})()
</script>
<?php
admin_page_end();
