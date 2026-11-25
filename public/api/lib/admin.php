<?php
// Espace organisateur : connexion par mot de passe (partagée entre ventes.php
// et controle.php), anti force brute, et mise en page commune.

const MAX_FAILS = 5;            // tentatives ratées autorisées...
const FAIL_WINDOW = 15 * 60;    // ...par tranche de 15 minutes et par adresse IP

function admin_start(): void
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    session_name('seppo_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/api/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
}

function admin_attempts(array $config): array
{
    $f = data_dir($config) . '/login-attempts.json';
    return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

function admin_recent_fails(array $config, string $ip): int
{
    return count(array_filter(admin_attempts($config)[$ip] ?? [], fn ($t) => $t > time() - FAIL_WINDOW));
}

function admin_record_fail(array $config, string $ip): void
{
    $all = admin_attempts($config);
    $all[$ip] = array_values(array_filter($all[$ip] ?? [], fn ($t) => $t > time() - FAIL_WINDOW));
    $all[$ip][] = time();
    file_put_contents(data_dir($config) . '/login-attempts.json', json_encode($all), LOCK_EX);
}

/**
 * Gère connexion / déconnexion. Retourne un message d'erreur éventuel.
 * Après une connexion réussie, recharge la page demandée.
 */
function admin_handle_auth(array $config, string $self): string
{
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header("Location: $self");
        exit;
    }
    $hash = (string) ($config['admin_password_hash'] ?? '');
    if ($action !== 'login' || $hash === '') {
        return '';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
    if (admin_recent_fails($config, $ip) >= MAX_FAILS) {
        return 'Trop de tentatives. Réessayez dans 15 minutes.';
    }
    if (password_verify((string) ($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header("Location: $self");
        exit;
    }
    admin_record_fail($config, $ip);
    sleep(1);
    return 'Mot de passe incorrect.';
}

function admin_logged(): bool
{
    return !empty($_SESSION['admin']);
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Classe CSS du badge d'un statut */
function status_class(string $status): string
{
    return ['RESERVED' => 'st-reserved', 'PAYED' => 'st-payed', 'MANUAL' => 'st-manual', 'REFUSED' => 'st-refused'][$status] ?? 'st-entered';
}

function fcfa(int $v): string
{
    return number_format($v, 0, ',', '.') . ' FCFA';
}

/** Retrouve un billet par son numéro : [orderId, order] ou null. */
function find_ticket(array $db, int $number): ?array
{
    foreach ($db['orders'] as $id => $o) {
        if (in_array($number, $o['numbers'], true)) {
            return [$id, $o];
        }
    }
    return null;
}

function admin_page_start(string $title, string $active): void
{
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> – Seppo Ndol'a Mbalè</title>
<style>
  :root { --bg:#0d0704; --card:#1f0f07; --line:#5a3a10; --gold:#f2b705; --text:#fff6ea; --muted:#d9c2a6;
          --ok:#2e9b47; --ok-bg:#0f2a16; --ko:#d62839; --ko-bg:#3a0d12; }
  * { box-sizing: border-box; }
  [hidden] { display:none !important; }
  .visually-hidden { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
  body { margin:0; min-height:100vh; background:radial-gradient(ellipse at 20% 0%, rgba(232,80,27,.25), transparent 55%), var(--bg);
         color:var(--text); font:16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding:20px 16px 32px; }
  main { max-width:640px; margin:0 auto; }
  h1 { margin:0 0 4px; font-size:28px; letter-spacing:.02em; }
  .sub { margin:0 0 18px; color:var(--muted); }
  nav { display:flex; gap:6px; margin:0 0 18px; }
  nav a { flex:1; display:flex; align-items:center; justify-content:center; text-align:center; padding:10px 6px; border-radius:8px; line-height:1.2; border:1px solid var(--line); color:var(--muted); text-decoration:none; font-weight:600; font-size:14px; }
  nav a.on { background:var(--gold); color:#1a0d06; border-color:var(--gold); }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
  label { display:block; font-size:14px; color:var(--muted); margin-bottom:6px; }
  input[type=password], input[type=text] { width:100%; padding:12px; border-radius:8px; border:1px solid #6b4a1c; background:#120804; color:var(--text); font-size:16px; }
  .btn { display:inline-block; width:100%; margin-top:14px; padding:14px 18px; border:0; border-radius:8px; cursor:pointer; text-align:center; text-decoration:none;
         background:linear-gradient(180deg,#ffd65a,var(--gold) 55%,#c98d00); color:#1a0d06; font-weight:800; font-size:15px; letter-spacing:.04em; text-transform:uppercase; }
  .btn-ok { background:var(--ok); color:#fff; font-size:18px; padding:18px; }
  .btn-danger { background:var(--ko); color:#fff; }
  .btn-ghost { background:none; border:1px solid var(--line); color:var(--muted); font-weight:600; text-transform:none; letter-spacing:0; }
  .btn:focus-visible, input:focus-visible, nav a:focus-visible { outline:3px solid #ffd65a; outline-offset:2px; }
  .link { background:none; border:0; color:var(--muted); text-decoration:underline; cursor:pointer; font-size:14px; padding:0; }
  .error { color:#ff8a7a; margin:10px 0 0; }
  .muted { color:var(--muted); font-size:14px; }
  .big { font-size:28px; font-weight:800; color:#ffd65a; white-space:nowrap; }
  .total { display:flex; flex-wrap:wrap; gap:12px; }
  .total div { flex:1 1 140px; }
  table { width:100%; border-collapse:collapse; font-size:15px; }
  th, td { padding:10px 6px; border-bottom:1px solid #3a2410; text-align:left; }
  th { color:var(--muted); font-weight:600; font-size:13px; text-transform:uppercase; letter-spacing:.05em; }
  th.num, td.num { text-align:right; white-space:nowrap; }
  th:first-child, td:first-child { width:50%; }
  .status { display:inline-block; padding:3px 10px; border-radius:4px; font-weight:800; font-size:13px; letter-spacing:.06em; white-space:nowrap; }
  .st-payed { background:#3d8bfd; color:#fff; }
  .st-manual { background:#ff9f1c; color:#1a0d06; }
  .st-entered { background:#2e9b47; color:#fff; }
  .st-refused { background:#d62839; color:#fff; }
  .st-reserved { background:#8e6cf0; color:#fff; }
  @media (max-width:420px) { .big { font-size:22px; } .card { padding:16px; } }
</style>
</head>
<body>
<main>
  <h1><?= e($title) ?></h1>
  <p class="sub">Seppo – Concert 100% Live « Ndol'a Mbalè »</p>
  <?php if (admin_logged()): ?>
  <nav>
    <a href="controle.php" class="<?= $active === 'controle' ? 'on' : '' ?>">Contrôle</a>
    <a href="vente-sur-place.php" class="<?= $active === 'manuel' ? 'on' : '' ?>">Sur place</a>
    <a href="billets.php" class="<?= $active === 'billets' ? 'on' : '' ?>">Billets</a>
    <a href="ventes.php" class="<?= $active === 'ventes' ? 'on' : '' ?>">Ventes</a>
  </nav>
  <?php endif ?>
    <?php
}

function admin_page_end(): void
{
    if (admin_logged()) { ?>
  <form method="post" style="text-align:center; margin-top:24px">
    <input type="hidden" name="action" value="logout">
    <button class="link" type="submit">Se déconnecter</button>
  </form>
    <?php } ?>
</main>
</body>
</html>
    <?php
}

/** Affiche la page de connexion (ou l'invitation à définir un mot de passe) et s'arrête. */
function admin_login_page(array $config, string $title, string $error): void
{
    admin_page_start($title, '');
    if ((string) ($config['admin_password_hash'] ?? '') === '') { ?>
  <div class="card">
    <p>Aucun mot de passe n'est encore défini pour cet espace.</p>
    <p class="muted">Sur l'ordinateur du projet : <code>npm run admin:password</code>, puis <code>npm run build</code> et renvoyer <code>api/config.php</code> par FTP.</p>
  </div>
    <?php } else { ?>
  <form class="card" method="post" autocomplete="off">
    <input type="hidden" name="action" value="login">
    <label for="password">Mot de passe organisateur</label>
    <input id="password" name="password" type="password" required autofocus>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
    <button class="btn" type="submit">Se connecter</button>
  </form>
    <?php }
    admin_page_end();
    exit;
}
