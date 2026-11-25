<?php
// Définit le mot de passe de l'espace organisateur (api/ventes.php).
// En ligne de commande uniquement :  npm run admin:password
// Le mot de passe n'est jamais stocké en clair : seule son empreinte
// (password_hash) est écrite dans config.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php introuvable (copier config.example.php en config.php).\n");
    exit(1);
}

$TTY = stream_isatty(STDIN); // déterminé une seule fois, avant toute lecture

function ask_hidden(string $prompt): string
{
    global $TTY;
    echo $prompt;
    $tty = $TTY;
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$pass = ask_hidden('Nouveau mot de passe (12 caractères minimum) : ');
if (mb_strlen($pass) < 12) {
    fwrite(STDERR, "✘ Trop court : 12 caractères minimum.\n");
    exit(1);
}
if (ask_hidden('Confirmer le mot de passe : ') !== $pass) {
    fwrite(STDERR, "✘ Les deux saisies ne correspondent pas.\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
$src = file_get_contents($configFile);
$line = "    'admin_password_hash' => " . var_export($hash, true) . ',';

if (preg_match("/^\s*'admin_password_hash'\s*=>.*$/m", $src)) {
    $src = preg_replace_callback("/^\s*'admin_password_hash'\s*=>.*$/m", fn () => $line, $src);
} else {
    // Insère avant le « ]; » final, en ajoutant la virgule manquante si besoin
    $pos = strrpos($src, '];');
    $before = rtrim(substr($src, 0, $pos));
    if (!str_ends_with($before, ',') && !str_ends_with($before, '[')) {
        $before .= ',';
    }
    $src = $before
        . "\n    // Espace organisateur (api/ventes.php) – défini avec : npm run admin:password\n$line\n"
        . substr($src, $pos);
}

// Vérifie le nouveau fichier AVANT de remplacer config.php : il ne peut jamais être cassé
$tmp = $configFile . '.tmp.php';
file_put_contents($tmp, $src);
try {
    $check = require $tmp;
    if (!is_array($check) || !password_verify($pass, $check['admin_password_hash'] ?? '')) {
        throw new RuntimeException('contenu inattendu');
    }
} catch (Throwable $e) {
    unlink($tmp);
    fwrite(STDERR, "✘ config.php n'a pas été modifié (" . $e->getMessage() . ").\n");
    exit(1);
}
chmod($tmp, fileperms($configFile) & 0777);
rename($tmp, $configFile);

echo "✔ Mot de passe enregistré dans config.php.\n";
echo "  Pensez à faire « npm run build » puis à renvoyer api/config.php par FTP.\n";
