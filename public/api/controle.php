<?php
// Espace organisateur – Contrôle à l'entrée : https://<domaine>/api/controle.php
// 1. Saisir le numéro du billet -> valide / déjà utilisé / inconnu.
// 2. « Valider l'entrée » : le billet est marqué utilisé (une seule fois).
// La validation se fait sous verrou : si deux contrôleurs valident le même
// billet au même instant, un seul réussit, l'autre voit « DÉJÀ UTILISÉ ».
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/reservations.php';

admin_start();
$error = admin_handle_auth($CONFIG, 'controle.php');
if (!admin_logged()) {
    admin_login_page($CONFIG, "Contrôle à l'entrée", $error);
}

/** « #700 », « 700 », « 0700 » -> 700 ; sinon null */
function parse_number($v): ?int
{
    $digits = preg_replace('/\D/', '', (string) $v);
    return ($digits !== '' && strlen($digits) <= 6) ? (int) $digits : null;
}

// ---- Actions (POST puis redirection, pour qu'un rechargement ne rejoue rien)
$action = $_POST['action'] ?? '';
$scan = false;
if ($action === 'scan') {
    // QR code scanné : on vérifie sa signature, puis on valide directement l'entrée
    $scan = true;
    $payload = (string) ($_POST['payload'] ?? '');
    $parsed = parse_qr_payload($CONFIG, $payload, with_db($CONFIG, fn (array &$db) => $db));
    if ($parsed === null || $parsed === 'forged') {
        $token = bin2hex(random_bytes(8));
        $_SESSION['results'][$token] = $parsed === null ? 'unreadable' : 'forged';
        preg_match('/^SEPPO-(\d{1,6})-/i', trim($payload), $m);
        header('Location: controle.php?scan=1&r=' . $token . (isset($m[1]) ? '&f=' . $m[1] : ''));
        exit;
    }
    $_POST['number'] = (string) $parsed;
    $action = 'validate';
}
if ($action === 'confirm' || $action === 'resend') {
    $n = parse_number($_POST['number'] ?? '');
    $db0 = with_db($CONFIG, fn (array &$db) => $db);
    $t = $n === null ? null : find_ticket($db0, $n);
    if (!$t) {
        $result = 'unknown';
    } elseif ($action === 'confirm') {
        $r = confirm_and_send_tickets($CONFIG, $t[0]);
        $result = !$r['ok'] ? 'confirm_failed' : (!empty($r['mailError']) ? 'confirmed_mailerror' : ($r['emailed'] ? 'confirmed_mailed' : 'confirmed_nomail'));
        $_SESSION['confirm_info'] = ['count' => count($r['numbers'] ?? []), 'email' => $r['email'] ?? null, 'error' => $r['error'] ?? null];
    } else {
        $r = resend_tickets($CONFIG, $t[0]);
        $result = $r['ok'] ? 'resent' : 'resend_failed';
        $_SESSION['confirm_info'] = ['count' => count($r['numbers'] ?? []), 'email' => $r['email'] ?? null, 'error' => $r['error'] ?? null];
    }
    $token = bin2hex(random_bytes(8));
    $_SESSION['results'][$token] = $result;
    $_SESSION['results'] = array_slice($_SESSION['results'], -20, null, true);
    header('Location: controle.php?n=' . ($n ?? '') . '&r=' . $token);
    exit;
}
if (in_array($action, ['validate', 'refuse'], true)) {
    $n = parse_number($_POST['number'] ?? '');
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200);
    $result = $n === null ? 'unknown' : with_db($CONFIG, function (array &$db) use ($n, $action, $reason, $CONFIG) {
        if (!find_ticket($db, $n)) {
            return 'unknown';
        }
        $db['checkins'] ??= [];
        $db['refused'] ??= [];

        // Annulation du billet (statut REFUSED)
        if ($action === 'refuse') {
            $r = refuse_ticket($db, $n, $reason);
            if ($r === 'cancelled') {
                refresh_sales_file($CONFIG, $db);
            }
            return $r;
        }
        // Validation de l'entrée (définitive)
        if (isset($db['refused'][$n])) {
            return 'refused'; // billet annulé : entrée interdite
        }
        if (isset($db['checkins'][$n])) {
            return 'already';
        }
        [, $o] = find_ticket($db, $n);
        if (ticket_status($db, $o, $n) === STATUS_RESERVED) {
            return 'reserved'; // paiement non confirmé : entrée interdite
        }
        $db['checkins'][$n] = ['at' => date('c')];
        refresh_sales_file($CONFIG, $db);
        return 'validated';
    });
    // Résultat propre à CETTE action (jeton à usage unique) : deux validations
    // rapprochées du même appareil ne peuvent pas mélanger leurs résultats.
    $token = bin2hex(random_bytes(8));
    $_SESSION['results'][$token] = $result;
    $_SESSION['results'] = array_slice($_SESSION['results'], -20, null, true);
    header('Location: controle.php?n=' . ($n ?? '') . '&r=' . $token . ($scan ? '&scan=1' : ''));
    exit;
}

// ---- Affichage
$token = (string) ($_GET['r'] ?? '');
$flash = $_SESSION['results'][$token] ?? null;
unset($_SESSION['results'][$token]);
$confirmInfo = $flash ? ($_SESSION['confirm_info'] ?? null) : null;
unset($_SESSION['confirm_info']);
$fromScan = isset($_GET['scan']);
$query = $_GET['n'] ?? '';
$n = $query === '' ? null : parse_number($query);

$db = with_db($CONFIG, fn (array &$db) => $db);
$ticket = $n !== null ? find_ticket($db, $n) : null;
$checkin = $n !== null ? ($db['checkins'][$n] ?? null) : null;
$refusal = $n !== null ? ($db['refused'][$n] ?? null) : null;
[$sold] = active_totals($db); // billets valides (hors annulés)
$entries = count($db['checkins'] ?? []);
$fmt = fn ($iso) => (new DateTime($iso))->format('d/m/Y à H:i:s');

// Formulaire d'annulation (REFUSED) commun aux cartes RESERVED et PAYED
$cancelForm = '';
if ($n !== null && $ticket) {
    ob_start(); ?>
    <details class="cancel" <?= $flash === 'reason_required' ? 'open' : '' ?>>
      <summary>Annuler ce billet (statut REFUSED)</summary>
      <form method="post" onsubmit="return confirm('Annuler définitivement le billet <?= e(format_ticket_number($n)) ?> ? Statut REFUSED, irréversible : il ne permettra plus d\'entrer.')">
        <input type="hidden" name="action" value="refuse">
        <input type="hidden" name="number" value="<?= e($n) ?>">
        <label for="reason" style="margin-top:10px">Motif de l'annulation *</label>
        <input id="reason" name="reason" type="text" maxlength="200" required minlength="3" placeholder="Ex : remboursé, billet signalé perdu, fraude…">
        <?php if ($flash === 'reason_required'): ?><p class="error" role="alert">Indiquez le motif de l'annulation (3 caractères minimum).</p><?php endif ?>
        <button class="btn btn-danger" type="submit">Annuler le billet</button>
      </form>
    </details>
<?php
    $cancelForm = ob_get_clean();
}

admin_page_start("Contrôle à l'entrée", 'controle');
?>
<style>
  .search { display:flex; gap:8px; }
  .search input { font-size:28px; font-weight:800; text-align:center; letter-spacing:.05em; padding:10px; }
  .search .btn { width:auto; margin:0; padding:0 22px; }
  .result { border-radius:12px; padding:22px 20px; margin-bottom:16px; text-align:center; border:2px solid; }
  .result h2 { margin:0 0 6px; font-size:30px; line-height:1.15; letter-spacing:.02em; }
  .result .num { font-size:44px; font-weight:900; line-height:1; margin:6px 0 10px; }
  .result p { margin:4px 0; }
  .ok { background:var(--ok-bg); border-color:var(--ok); }
  .ok h2 { color:#5fe07f; }
  .ko { background:var(--ko-bg); border-color:var(--ko); }
  .ko h2 { color:#ff6b78; }
  .info { background:var(--card); border-color:var(--gold); }
  .resv { background:#2b1a05; border-color:#ff9f1c; }
  .resv h2 { color:#ffb347; }
  .client { margin:14px 0 4px; padding:14px 16px; border-radius:10px; background:rgba(0,0,0,.35); border:1px solid #6b4a1c; text-align:left; }
  .client h3 { margin:0 0 8px; font-size:13px; letter-spacing:.12em; text-transform:uppercase; color:#ffb347; }
  .client dl { margin:0; display:grid; grid-template-columns:auto minmax(0, 1fr); gap:6px 14px; font-size:15px; }
  .client dt { color:var(--muted); font-size:13px; padding-top:2px; }
  .client dd { margin:0; overflow-wrap:anywhere; }
  .client a { color:var(--gold); }
  .client__tickets a { font-weight:800; text-decoration:none; color:var(--text); }
  .client__tickets a.cur { color:#ffb347; }
  .client__tickets .status { margin-left:6px; font-size:11px; padding:2px 6px; }
  @media (max-width:420px) { .client dl { grid-template-columns:1fr; gap:2px; } .client dd { margin-bottom:8px; } }
  .info h2 { color:#ffd65a; }
  .who { font-size:20px; font-weight:700; text-transform:uppercase; }
  .tier { display:inline-block; padding:3px 10px; background:var(--gold); color:#1a0d06; font-weight:800; border-radius:4px; font-size:14px; letter-spacing:.05em; text-transform:uppercase; }
  .counter { text-align:center; margin:0 0 14px; }
  details.cancel { margin-top:14px; text-align:left; }
  details.cancel summary { cursor:pointer; color:var(--muted); font-size:14px; text-align:center; list-style:none; }
  details.cancel summary::-webkit-details-marker { display:none; }
  details.cancel summary::before { content:'⚠ '; }
  .btn-danger { background:var(--ko); color:#fff; }
  .note { margin:0 0 8px; padding:8px 10px; border-radius:6px; background:rgba(255,255,255,.06); font-size:14px; }
  .btn-scan { margin:0 0 12px; font-size:18px; padding:18px; }
  #scanner { display:none; position:relative; margin:0 0 16px; border-radius:12px; overflow:hidden; border:2px solid var(--gold); background:#000; }
  #scanner.on { display:block; }
  #scanner video { display:block; width:100%; max-height:60vh; object-fit:cover; }
  #scanner .frame { position:absolute; inset:15%; border:3px solid rgba(255,214,90,.9); border-radius:12px; box-shadow:0 0 0 999px rgba(0,0,0,.35); pointer-events:none; }
  #scanner .hint { position:absolute; left:0; right:0; bottom:10px; text-align:center; font-weight:700; text-shadow:0 1px 3px #000; }
  #scan-stop { margin:0 0 12px; }
  .or { text-align:center; color:var(--muted); font-size:14px; margin:4px 0 10px; }
</style>

  <p class="counter muted">Entrées validées : <strong style="color:#fff6ea"><?= $entries ?></strong> / <?= $sold ?> billets valides</p>

  <button id="scan-start" class="btn btn-ok btn-scan" type="button">📷 <?= $fromScan ? 'Scanner le billet suivant' : 'Scanner un QR code' ?></button>
  <div id="scanner" aria-live="polite">
    <video id="scan-video" playsinline muted></video>
    <div class="frame"></div>
    <div class="hint" id="scan-hint">Placez le QR code du billet dans le cadre</div>
  </div>
  <button id="scan-stop" class="btn btn-ghost" type="button" hidden>Fermer la caméra</button>
  <p id="scan-error" class="error" role="alert" hidden></p>
  <form id="scan-form" method="post" hidden>
    <input type="hidden" name="action" value="scan">
    <input type="hidden" name="payload" id="scan-payload">
  </form>
  <p class="or">ou saisir le numéro</p>

  <form class="card search" method="get" autocomplete="off">
    <input type="text" name="n" inputmode="numeric" placeholder="N° (ex : 001)" aria-label="Numéro du billet"
           value="<?= $n !== null && $flash !== 'validated' && !$fromScan ? e($n) : '' ?>" <?= $fromScan ? '' : 'autofocus' ?> required>
    <button class="btn" type="submit">Vérifier</button>
  </form>

<?php if ($flash === 'forged'): ?>
  <div class="result ko">
    <h2>⛔ QR CODE FALSIFIÉ</h2>
    <?php if (isset($_GET['f'])): ?><div class="num"><?= e(format_ticket_number((int) parse_number($_GET['f']))) ?></div><?php endif ?>
    <p>Ce QR code n'est pas valide : faux billet, ou billet annulé dont le numéro a été réattribué à un autre client.</p>
    <p><strong>Ne pas laisser entrer.</strong> Vérifiez la pièce d'identité et contactez l'organisation.</p>
  </div>

<?php elseif ($flash === 'unreadable'): ?>
  <div class="result ko">
    <h2>QR CODE NON RECONNU</h2>
    <p>Ce n'est pas un billet du concert. Scannez le QR code du billet, ou saisissez son numéro.</p>
  </div>

<?php elseif ($query !== '' && $n === null): ?>
  <div class="result ko"><h2>Numéro invalide</h2><p>Saisissez le numéro inscrit sur le billet, par exemple 001 ou 00301.</p></div>

<?php elseif ($n !== null && !$ticket): ?>
  <div class="result ko">
    <h2>⛔ BILLET INCONNU</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p>Ce numéro ne correspond à aucun billet vendu.</p>
    <p><strong>Ne pas laisser entrer.</strong></p>
  </div>

<?php elseif ($ticket):
    [$orderId, $o] = $ticket;
    $tierName = TIERS[$o['tierId']]['name'] ?? $o['tierId'];
    $status = ticket_status($db, $o, $n);
    $statusCls = status_class($status); ?>

  <?php if ($refusal): ?>
  <div class="result ko">
    <?php if ($flash === 'cancelled'): ?><p class="note">Billet annulé.</p><?php endif ?>
    <h2>⛔ BILLET ANNULÉ</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p class="who"><?= e($o['holder']) ?></p>
    <p><span class="tier"><?= e($tierName) ?></span> <span class="status <?= $statusCls ?>"><?= e($status) ?></span></p>
    <p>Annulé le <strong><?= e($fmt($refusal['at'])) ?></strong></p>
    <p>Motif : <strong><?= e($refusal['reason']) ?></strong></p>
    <p><strong>Ne pas laisser entrer.</strong></p>
  </div>

  <?php elseif ($flash === 'validated'): ?>
  <div class="result ok">
    <h2>✅ ENTRÉE VALIDÉE</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p class="who"><?= e($o['holder']) ?></p>
    <p><span class="tier"><?= e($tierName) ?></span> <span class="status <?= $statusCls ?>"><?= e($status) ?></span></p>
    <p class="muted">Bonne soirée ! Saisissez le billet suivant ci-dessus.</p>
  </div>

  <?php elseif ($checkin): ?>
  <div class="result ko">
    <h2>⛔ DÉJÀ UTILISÉ</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p class="who"><?= e($o['holder']) ?></p>
    <p><span class="tier"><?= e($tierName) ?></span> <span class="status <?= $statusCls ?>"><?= e($status) ?></span></p>
    <p>Entrée déjà validée le <strong><?= e($fmt($checkin['at'])) ?></strong>.</p>
    <p><strong>Ne pas laisser entrer.</strong></p>
  </div>

  <?php elseif ($status === STATUS_RESERVED):
      $resNums = array_values(array_filter($o['numbers'], fn ($x) => ticket_status($db, $o, $x) === STATUS_RESERVED));
      $resLabels = implode(', ', array_map('format_ticket_number', $resNums));
      $resTotal = count($resNums) * (TIERS[$o['tierId']]['price'] ?? 0); ?>
  <div class="result resv">
    <?php if ($flash === 'reserved'): ?><p class="note">Entrée refusée : paiement non confirmé.</p><?php endif ?>
    <?php if ($flash === 'confirm_failed' && $confirmInfo): ?><p class="note"><?= e($confirmInfo['error']) ?></p><?php endif ?>
    <h2>⏳ RÉSERVÉ – PAIEMENT À CONFIRMER</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p class="who"><?= e($o['holder']) ?></p>
    <p><span class="tier"><?= e($tierName) ?></span> <span class="status <?= $statusCls ?>"><?= e($status) ?></span></p>
    <?php
      $digits = preg_replace('/\D/', '', (string) $o['phone']);
      $waNumber = strlen($digits) === 9 ? '237' . $digits : $digits; // numéro camerounais sans indicatif
    ?>
    <div class="client" aria-label="Informations du client">
      <h3>Client</h3>
      <dl>
        <dt>Nom</dt><dd><?= e($o['holder']) ?></dd>
        <dt>Téléphone de paiement</dt>
        <dd>
          <a href="tel:<?= e(str_starts_with((string) $o['phone'], '+') ? '+' . $digits : $digits) ?>"><?= e($o['phone']) ?></a>
          <?php if ($waNumber !== ''): ?> · <a href="https://wa.me/<?= e($waNumber) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif ?>
        </dd>
        <dt>Email</dt><dd><?= $o['email'] ? '<a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>' : '<span class="muted">non renseigné (remettre le billet physique)</span>' ?></dd>
        <dt>Réservé le</dt><dd><?= e($fmt($o['allocatedAt'])) ?></dd>
        <dt>Référence</dt><dd><?= e($orderId) ?></dd>
        <dt>Gamme</dt><dd><?= e($tierName) ?> · <?= e(fcfa(TIERS[$o['tierId']]['price'] ?? 0)) ?> / billet</dd>
        <dt>Billets</dt>
        <dd class="client__tickets">
          <?php foreach ($o['numbers'] as $x): $st = ticket_status($db, $o, $x); ?>
            <a href="controle.php?n=<?= $x ?>" class="<?= $x === $n ? 'cur' : '' ?>"><?= e(format_ticket_number($x)) ?></a>
            <span class="status <?= status_class($st) ?>"><?= e($st) ?></span><br>
          <?php endforeach ?>
        </dd>
        <dt>À vérifier</dt><dd><strong><?= e(fcfa($resTotal)) ?></strong> pour <?= count($resNums) ?> billet(s) en attente (<?= e($resLabels) ?>), au nom de <?= e($o['holder']) ?>, payé depuis le <?= e($o['phone']) ?>, sur le relevé Maviance</dd>
      </dl>
    </div>
    <p><strong>Ne pas laisser entrer tant que le paiement n'est pas confirmé.</strong></p>
    <form method="post" onsubmit="return confirm(<?= e(json_encode('Confirmer le paiement Maviance de ' . count($resNums) . ' billet(s) (' . $resLabels . ') pour ' . fcfa($resTotal) . ' ?\n\nLes billets passeront à PAYED' . ($o['email'] ? ' et seront envoyés par email à ' . $o['email'] : ' (pas d\'email : remettre les billets physiques)') . '.', JSON_UNESCAPED_UNICODE)) ?>)">
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="number" value="<?= e($n) ?>">
      <button class="btn btn-ok" type="submit">✔ Confirmer le paiement (PAYED)</button>
    </form>
    <?= $cancelForm ?>
  </div>

  <?php else: ?>
  <div class="result info">
    <?php if ($confirmInfo && in_array($flash, ['confirmed_mailed', 'confirmed_nomail', 'confirmed_mailerror', 'resent', 'resend_failed'], true)): ?>
    <p class="note"><?= e([
        'confirmed_mailed' => 'Paiement confirmé : ' . $confirmInfo['count'] . ' billet(s) PAYED, envoyé(s) par email à ' . $confirmInfo['email'] . '.',
        'confirmed_nomail' => 'Paiement confirmé : ' . $confirmInfo['count'] . ' billet(s) PAYED. Pas d\'email : remettez le(s) billet(s) physique(s).',
        'confirmed_mailerror' => 'Paiement confirmé (PAYED), mais l\'email n\'a pas pu être envoyé. Utilisez « Renvoyer les billets par email ».',
        'resent' => 'Billets renvoyés par email à ' . $confirmInfo['email'] . '.',
        'resend_failed' => (string) $confirmInfo['error'],
    ][$flash]) ?></p>
    <?php endif ?>
    <h2>BILLET VALIDE</h2>
    <div class="num"><?= e(format_ticket_number($n)) ?></div>
    <p class="who"><?= e($o['holder']) ?></p>
    <p><span class="tier"><?= e($tierName) ?></span> <span class="status <?= $statusCls ?>"><?= e($status) ?></span></p>
    <p class="muted">Pas encore utilisé · <?= is_manual($o) ? 'Payé en espèces' : 'Payé en ligne' ?> · Commande <?= e($orderId) ?></p>
    <form method="post">
      <input type="hidden" name="action" value="validate">
      <input type="hidden" name="number" value="<?= e($n) ?>">
      <button class="btn btn-ok" type="submit">✔ Valider l'entrée</button>
    </form>
    <?php if (in_array($status, [STATUS_PAYED, STATUS_MANUAL], true) && !empty($o['email'])): ?>
    <form method="post" onsubmit="return confirm('Renvoyer les billets de cette commande à <?= e($o['email']) ?> ?')">
      <input type="hidden" name="action" value="resend">
      <input type="hidden" name="number" value="<?= e($n) ?>">
      <button class="btn btn-ghost" type="submit">✉ Renvoyer les billets par email</button>
    </form>
    <?php endif ?>
    <?php if ($status === STATUS_PAYED): ?>
    <?= $cancelForm ?>
    <?php endif ?>
  </div>
  <?php endif ?>
<?php endif ?>
<script src="../vendor/jsQR.js"></script>
<script>
(() => {
  const $ = (id) => document.getElementById(id)
  const video = $('scan-video'), box = $('scanner'), startBtn = $('scan-start'), stopBtn = $('scan-stop'), err = $('scan-error')
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  let stream = null, running = false

  // Retour tactile du dernier résultat : 1 vibration = entrée OK, 3 = refus
  const kind = <?= json_encode($flash === 'validated' ? 'ok' : (in_array($flash, ['already', 'forged', 'unreadable', 'unknown', 'refused', 'reserved'], true) || ($checkin && $flash === null && $fromScan) ? 'ko' : '')) ?>;
  if (kind && navigator.vibrate) navigator.vibrate(kind === 'ok' ? 120 : [250, 120, 250, 120, 250])

  const showError = (msg) => { err.textContent = msg; err.hidden = false }

  const stop = () => {
    running = false
    if (stream) stream.getTracks().forEach((t) => t.stop())
    stream = null
    box.classList.remove('on')
    stopBtn.hidden = true
    startBtn.hidden = false
  }

  const tick = () => {
    if (!running) return
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
      const scale = Math.min(1, 640 / video.videoWidth)
      canvas.width = Math.round(video.videoWidth * scale)
      canvas.height = Math.round(video.videoHeight * scale)
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      const img = ctx.getImageData(0, 0, canvas.width, canvas.height)
      const code = window.jsQR && jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' })
      if (code && code.data) {
        stop()
        $('scan-hint').textContent = 'QR code lu…'
        $('scan-payload').value = code.data
        $('scan-form').submit()
        return
      }
    }
    requestAnimationFrame(tick)
  }

  startBtn.addEventListener('click', async () => {
    err.hidden = true
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showError("Caméra indisponible sur ce navigateur. Le site doit être ouvert en https://. Saisissez le numéro du billet.")
      return
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
    } catch (e) {
      showError(e.name === 'NotAllowedError'
        ? "Accès à la caméra refusé. Autorisez la caméra pour ce site dans le navigateur, ou saisissez le numéro."
        : "Impossible d'ouvrir la caméra. Saisissez le numéro du billet.")
      return
    }
    video.srcObject = stream
    await video.play()
    box.classList.add('on')
    startBtn.hidden = true
    stopBtn.hidden = false
    running = true
    box.scrollIntoView({ behavior: 'smooth', block: 'start' })
    requestAnimationFrame(tick)
  })
  stopBtn.addEventListener('click', stop)
  window.addEventListener('pagehide', stop)
})()
</script>
<?php
admin_page_end();
