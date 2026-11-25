<?php
// Copier ce fichier en config.php et le compléter. NE PAS le partager.
return [
    // Numérotation par catégorie : voir NUMBERING dans lib/store.php
    'max_tickets_per_order' => 10,

    // Dossier des données (registre des billets + fichier Excel des ventes).
    // Par défaut : api/data/ (protégé du web, accessible par FTP).
    'data_dir' => __DIR__ . '/data',

    // Envoi des billets par email
    'smtp' => [
        'host' => 'mail.example.com',
        'port' => 465,            // 465 = SSL, 587 = STARTTLS
        'user' => 'billetterie@example.com',
        'pass' => 'mot-de-passe',
        'from' => 'billetterie@example.com',
        'from_name' => 'Billetterie Seppo',
    ],

    // Adresse publique du site (affiche du concert dans l'email). Ex: https://billets.mondomaine.com
    'site_url' => '',

    // Mot de passe de l'espace organisateur (api/ventes.php) : ne pas remplir à la main,
    // utiliser « npm run admin:password » qui écrit son empreinte ici.
    'admin_password_hash' => '',
];
