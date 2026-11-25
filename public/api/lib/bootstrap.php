<?php
// Chargement commun aux points d'entrée de l'API
declare(strict_types=1);
date_default_timezone_set('Africa/Douala');
mb_internal_encoding('UTF-8');

$configFile = getenv('BILLETTERIE_CONFIG') ?: __DIR__ . '/../config.php'; // surcharge = tests locaux
if (!is_file($configFile)) {
    http_response_code(500);
    exit(json_encode(['error' => 'config.php manquant sur le serveur.']));
}
$CONFIG = require $configFile;

require __DIR__ . '/store.php';
require __DIR__ . '/xlsx.php';

const ORDER_RE = '/^SEP-[A-Z0-9]{8}$/';

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lit le corps JSON d'une requête POST (taille limitée). */
function read_json(int $maxBytes): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['error' => 'Méthode non autorisée.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        respond(413, ['error' => 'Requête trop volumineuse.']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Requête illisible par le serveur (format inattendu). Rechargez la page et réessayez.']);
    }
    return $data;
}

/** Régénère le fichier Excel des ventes (data/ventes.xlsx). Ne bloque jamais le client. */
function refresh_sales_file(array $config, array $db): void
{
    try {
        write_sales_xlsx($db, data_dir($config) . '/ventes.xlsx');
    } catch (Throwable $e) {
        error_log('[billetterie] ventes.xlsx non mis à jour : ' . $e->getMessage());
    }
}
