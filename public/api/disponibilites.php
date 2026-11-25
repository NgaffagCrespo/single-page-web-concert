<?php
// GET /api/disponibilites.php — places restantes par catégorie (page publique).
// Permet d'afficher « Complet » et de limiter la quantité choisissable.
require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(405, ['error' => 'Méthode non autorisée.']);
}
try {
    $db = with_db($CONFIG, fn (array &$db) => $db);
} catch (Throwable $e) {
    error_log('[billetterie] ' . $e->getMessage());
    respond(500, ['error' => 'Erreur serveur.']);
}
$out = [];
foreach (array_keys(TIERS) as $id) {
    $out[$id] = ['remaining' => tier_remaining($db, $id), 'capacity' => NUMBERING[$id]['max']];
}
respond(200, $out);
