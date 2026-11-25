# Billetterie – Seppo, Concert 100% Live « Ndol'a Mbalè »

Site React (Vite) + API PHP (numérotation des billets, envoi des emails, fichier Excel des ventes).
Se déploie sur un hébergement PHP classique, par simple envoi FTP.

## Lancer en local

```bash
npm install
npm run api   # terminal 1 – API PHP sur :8000 (données de test dans .data/)
npm run dev   # terminal 2 – site sur http://localhost:5173
```

## Parcours

1. `/` : le client choisit une gamme, le nombre de billets et indique nom, téléphone (9 chiffres minimum) et email.
2. Ses billets sont **réservés** (statut `RESERVED`, numéros attribués) et il reçoit un email de réservation (sans billet). Le site affiche le message pendant 10 secondes, puis le redirige vers le lien de paiement Maviance de la gamme, dans le même onglet. Il y indique le même nom et la même quantité.
3. L'organisateur vérifie le paiement de son côté, puis, dans l'espace organisateur (Contrôle ou Billets) : **Confirmer le paiement** → les billets passent à `PAYED` et le client reçoit ses **billets PDF par email** (avec QR code). Ou **Annuler** → `REFUSED`.
4. Le client peut aussi passer récupérer un billet physique.

## Déployer avec FileZilla

1. `npm run build` → génère le dossier `dist/`.
2. Avec FileZilla, envoyer **le contenu** de `dist/` (pas le dossier lui-même) dans le dossier web de l'hébergement (souvent `public_html/` ou `www/`).
   Afficher les fichiers cachés (Serveur → Forcer l'affichage des fichiers cachés) pour bien envoyer les `.htaccess`.

Pour une mise à jour du site, renvoyer le contenu de `dist/` : le dossier `api/data/` (les ventes) n'est jamais écrasé, il n'existe que sur le serveur.

## Numérotation des billets et capacités

Chaque catégorie a un **nombre exact de billets** (capacité) et sa propre série de numéros, attribuée par le serveur (un numéro n'est jamais donné deux fois) :

| Catégorie | Capacité | Numéros |
|---|---|---|
| Standard | 497 | #001 … #099, #00100 … #00300, puis #10300 … #10496 |
| VIP | 299 | #00301 … #00500, puis #10500 … #10598 |
| VVIP | 198 | #00501 … #00698 |
| Gold | 102 | #00701 … #00802 |

Règle : quand la plage normale est pleine, on ajoute un « 1 » devant son dernier numéro et on continue (00300 → 10300). Capacité atteinte : la catégorie est **complète** (bouton « Complet » sur le site, refus en ligne et sur place). Un billet **annulé (REFUSED) libère sa place** : son numéro peut être réattribué (en ligne ou sur place), après les numéros jamais utilisés. L'annulation est alors archivée dans l'onglet « Annulations » de l'Excel, et l'ancien QR code du client annulé n'est plus accepté (le QR est lié au numéro et à la commande). Capacités modifiables dans `public/api/lib/store.php` (`NUMBERING`, champ `max`).

Ventes en ligne et sur place partagent la série de leur catégorie. À l'entrée, on peut saisir `1`, `001` ou `#001`.
Avant la mise en ligne, partir d'un registre vide (`api/data/` absent sur le serveur).

## Fichier des ventes

Mis à jour à chaque paiement réussi.

- Onglet **Ventes** : `N° billet | Gamme | Montant (FCFA) | Paiement | Statut | Nom | Téléphone | Email | Commande | Date de paiement | Entrée validée le`
  - Paiement : `En ligne` ou `Espèces` · Statut : `RESERVED`, `PAYED`, `MANUAL`, `ENTRÉE VALIDÉE`, `REFUSED`
  - Les totaux ne comptent que les billets payés (`PAYED`, `MANUAL`, entrés) ; `RESERVED` et `REFUSED` sont comptés à part.
- Onglet **Résumé** : billets et montants par gamme, total, répartition en ligne / espèces, nombre d'entrées validées.

**Télécharger :** ouvrir **`https://<domaine>/api/ventes.php`** (ordinateur ou téléphone), se connecter avec le mot de passe organisateur → résumé des ventes + bouton « Télécharger le fichier Excel » (toujours la dernière version).

Définir ou changer le mot de passe (12 caractères minimum, jamais stocké en clair) :
```bash
npm run admin:password   # puis npm run build et renvoyer api/config.php par FTP
```
Après 5 mots de passe erronés, l'accès est bloqué 15 minutes.

Le fichier est aussi disponible par FTP : `api/data/ventes.xlsx`. Le dossier `api/data/` est inaccessible depuis le web.
**Sauvegarder régulièrement `api/data/tickets.json` et `api/data/ticket-secret.key`** : le registre des numéros attribués et la clé qui signe les QR codes (sans elle, les QR déjà émis ne seraient plus reconnus).

## Contrôle à l'entrée (jour du concert)

Ouvrir **`https://<domaine>/api/controle.php`** sur le téléphone de chaque contrôleur (même mot de passe organisateur).

- **📷 Scanner un QR code** : la caméra lit le QR du billet (papier ou écran) et **valide directement** l'entrée. Le téléphone vibre 1 fois si l'entrée est acceptée, 3 fois si elle est refusée. Le site doit être en **https://** pour accéder à la caméra.
- **ou saisir le numéro** :

1. Saisir le numéro du billet (`1`, `001` ou `#001`) → **Vérifier**.
2. Résultat : **BILLET VALIDE** (bouton « Valider l'entrée »), **⛔ DÉJÀ UTILISÉ** (date et heure de la 1re entrée), ou **⛔ BILLET INCONNU**.
3. **Valider l'entrée** : le billet ne pourra plus jamais être validé, même depuis un autre téléphone au même instant.

Chaque QR code contient le numéro et un **code de sécurité signé par le serveur** : un QR fabriqué à la main est refusé (« QR CODE FALSIFIÉ »).

**Statuts et transitions** : `RESERVED` (réservé en ligne) → `PAYED` (paiement confirmé par l'organisateur, billets envoyés par email) ou `REFUSED` (annulé). `PAYED` → `REFUSED` (annulation, avec motif). `PAYED` ou `MANUAL` (vendu sur place) → `ENTRÉE VALIDÉE`. Un billet `RESERVED` ne peut pas entrer. `ENTRÉE VALIDÉE` et `REFUSED` sont **définitifs** : ils ne peuvent plus changer. Un billet REFUSED est refusé à l'entrée (scan ou saisie). Les billets annulés sont exclus des totaux et comptés à part (page Ventes et Excel).

## Vente sur place (espèces)

**`https://<domaine>/api/vente-sur-place.php`** (même mot de passe organisateur) : nom du client, téléphone (obligatoire, 9 chiffres minimum), email facultatif, gamme, nombre de billets et **numéro de chaque billet physique** (obligatoire : à saisir, ou à choisir dans la liste des numéros libres de la plage) → confirmation du montant reçu → statut **MANUAL**.
Si l'email du client est renseigné (facultatif), ses billets PDF avec QR code lui sont envoyés automatiquement, comme pour un paiement en ligne confirmé. Le serveur refuse un numéro hors de la plage de la gamme ou déjà attribué. À l'entrée, le billet se contrôle par son numéro. Un formulaire envoyé deux fois ne crée pas de doublon.

## Liste des billets

**`https://<domaine>/api/billets.php`** (onglet « Billets ») : par défaut les billets **valides** (tout sauf REFUSED), du plus récent au plus ancien.
Filtres : Valides, PAYED, MANUAL, Entrée validée, REFUSED, Tous (avec leur nombre) et par gamme. Recherche : `#00301` ou jusqu'à 5 chiffres (numéro), 6 chiffres et plus (téléphone), texte (nom ou email). Toucher un billet ouvre son écran de contrôle. Les billets **PAYED** ont un bouton « Annuler » (motif obligatoire, passage irréversible à REFUSED).

## À configurer

| Quoi | Où |
|---|---|
| Liens de paiement, références SEPPO1-4, prix affichés | `src/config/tickets.js` |
| Prix utilisés dans le fichier Excel | `public/api/lib/store.php` (`TIERS`) |
| SMTP, adresse du site | `public/api/config.php` (modèle : `config.example.php`) |
| Plages de numérotation | `public/api/lib/store.php` (`NUMBERING`) |
| Textes des emails (réservation et billets) | `public/api/lib/email_template.php` |
| Mise en page des billets PDF | `public/api/lib/ticket_pdf.php` |
| Modèles de billets | `public/templates/*.jpg` |
| Logos partenaires | `public/partners/` + `src/config/event.js` |

Tester l'email : `npm run test:mail` (connexion) ou `npm run test:mail -- vous@exemple.com` (envoi d'un exemple).

Sécurité de la réservation : 10 réservations par heure au plus par connexion, et contrôle de chaque champ côté serveur.
