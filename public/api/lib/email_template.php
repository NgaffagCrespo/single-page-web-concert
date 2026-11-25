<?php
// Emails envoyés aux clients : réservation (sans billet) et billets (PDF joints).
// Deux langues : français et anglais, selon la langue choisie par le client sur le site.
// Les textes se modifient librement ici. Mise en page en tableaux + styles en ligne :
// c'est ce que Gmail, Outlook et les messageries mobiles affichent le mieux.

const EMAIL_CONTACT = [
    'phone' => '+237 690 848 418',
    'phoneHref' => 'tel:+237690848418',
    'signature' => 'Tubuntu Management',
];

const TIER_COLORS = ['standard' => '#e8501b', 'vip' => '#d99a1e', 'vvip' => '#3f9b2f', 'gold' => '#f2b705'];

const EMAIL_TEXTS = [
    'fr' => [
        'date' => 'Mercredi 30 décembre 2026',
        'doors' => 'À partir de 19H',
        'venue' => 'Falaise Diamond de Bonanjo, Douala',
        'ticketWord' => 'Billet',
        'subject.reserved.one' => '{first}, votre place pour Seppo – Ndol\'a Mbalè est réservée ✨',
        'subject.reserved.many' => '{first}, vos {n} places pour Seppo – Ndol\'a Mbalè sont réservées ✨',
        'subject.paid.one' => '{first}, votre place pour Seppo – Ndol\'a Mbalè vous attend ✨',
        'subject.paid.many' => '{first}, vos {n} places pour Seppo – Ndol\'a Mbalè vous attendent ✨',
        'joined.one' => 'Votre billet est joint', 'joined.many' => 'Vos billets sont joints',
        'yourTickets.one' => 'Votre billet', 'yourTickets.many' => 'Vos billets',
        'each.one' => 'votre billet', 'each.many' => 'chaque billet',
        'ticketsLabel.one' => 'Billet', 'ticketsLabel.many' => 'Billets',
        'yourTicketLower.one' => 'votre billet', 'yourTicketLower.many' => 'vos billets',
        'reservedHeading.one' => 'Votre place réservée', 'reservedHeading.many' => 'Vos places réservées',
        'main.reserved' => 'Votre réservation est bien enregistrée. Dès que votre paiement sera confirmé, vous recevrez {tickets} par email sous peu.',
        'main.paid' => '{joined} à cet email au format PDF.',
        'ref' => 'Référence de réservation : {ref}',
        'preheader.reserved' => 'Votre réservation est enregistrée : vous recevrez {tickets} sous peu.',
        'preheader.paid' => "C'est un honneur de vous accueillir le 30 décembre. {joined} à cet email.",
        'bullets.reserved' => [
            'Vous pouvez également passer récupérer votre billet physique.',
            'Conservez la référence de votre réservation : elle vous sera demandée en cas de besoin.',
        ],
        'bullets.paid' => [
            "Présentez {each} à l'entrée, imprimé ou sur votre téléphone.",
            'Vous pouvez également passer récupérer votre billet physique.',
            "Chaque numéro est unique et n'est valable qu'une seule fois : ne le partagez pas.",
            "Nous vous conseillons d'arriver tôt pour profiter pleinement de la soirée.",
        ],
        'greeting' => 'Cher(e) {first},',
        'honour.text' => "C'est un immense honneur de vous compter parmi nos invités.",
        'honour.html' => "C'est un <strong style=\"color:#ffd65a;\">immense honneur</strong> de vous compter parmi nos invités.",
        'presence.text' => "Votre présence au concert 100% Live « Ndol'a Mbalè » de Seppo n'est pas un simple achat : c'est un geste de confiance et de soutien envers l'artiste, et nous vous en sommes profondément reconnaissants. Le 30 décembre, nous préparons une soirée à la hauteur de cet honneur.",
        'presence.html' => "Votre présence au concert 100% Live « Ndol'a Mbalè » de Seppo n'est pas un simple achat : c'est un geste de confiance et de soutien envers l'artiste, et nous vous en sommes <strong style=\"color:#fff6ea;\">profondément reconnaissants</strong>. Le 30 décembre, nous préparons une soirée à la hauteur de cet honneur.",
        'yourBooking' => 'VOTRE RÉSERVATION',
        'holder' => 'Titulaire : {holder}',
        'category' => 'Catégorie : {tier}',
        'numbersLine' => '{label} : {list}',
        'event' => "L'ÉVÉNEMENT",
        'question.text' => 'Une question ? Nous restons à votre entière disposition au {phone}.',
        'question.html' => 'Une question ? Nous restons à votre entière disposition au {phoneLink}.',
        'thanks' => 'Merci de faire de cette soirée un moment inoubliable.',
        'gratitude' => 'Avec toute notre gratitude,',
        'inTheNameOf' => 'Au nom de',
        'dateLabel' => 'Date',
        'venueLabel' => 'Lieu',
        'patronage.html' => 'Sous le haut patronage de Monsieur <span style="color:#f2b705;">le Gouverneur</span> de la Région du Littoral',
        'posterAlt' => "Seppo – Concert 100% Live Ndol'a Mbalè",
        'footer' => "Seppo – Concert 100% Live « Ndol'a Mbalè »",
    ],
    'en' => [
        'date' => 'Wednesday, December 30, 2026',
        'doors' => 'From 7 PM',
        'venue' => 'Falaise Diamond, Bonanjo, Douala',
        'ticketWord' => 'Ticket',
        'subject.reserved.one' => '{first}, your seat for Seppo – Ndol\'a Mbalè is reserved ✨',
        'subject.reserved.many' => '{first}, your {n} seats for Seppo – Ndol\'a Mbalè are reserved ✨',
        'subject.paid.one' => '{first}, your seat for Seppo – Ndol\'a Mbalè is waiting for you ✨',
        'subject.paid.many' => '{first}, your {n} seats for Seppo – Ndol\'a Mbalè are waiting for you ✨',
        'joined.one' => 'Your ticket is attached', 'joined.many' => 'Your tickets are attached',
        'yourTickets.one' => 'Your ticket', 'yourTickets.many' => 'Your tickets',
        'each.one' => 'your ticket', 'each.many' => 'each ticket',
        'ticketsLabel.one' => 'Ticket', 'ticketsLabel.many' => 'Tickets',
        'yourTicketLower.one' => 'your ticket', 'yourTicketLower.many' => 'your tickets',
        'reservedHeading.one' => 'Your reserved seat', 'reservedHeading.many' => 'Your reserved seats',
        'main.reserved' => 'Your booking has been recorded. As soon as your payment is confirmed, you will receive {tickets} by email shortly.',
        'main.paid' => '{joined} to this email as a PDF.',
        'ref' => 'Booking reference: {ref}',
        'preheader.reserved' => 'Your booking is recorded: you will receive {tickets} shortly.',
        'preheader.paid' => 'It is an honour to welcome you on December 30. {joined} to this email.',
        'bullets.reserved' => [
            'You can also come and collect your physical ticket.',
            'Keep your booking reference: you may be asked for it if needed.',
        ],
        'bullets.paid' => [
            'Show {each} at the entrance, printed or on your phone.',
            'You can also come and collect your physical ticket.',
            'Each number is unique and valid only once: do not share it.',
            'We recommend arriving early to fully enjoy the evening.',
        ],
        'greeting' => 'Dear {first},',
        'honour.text' => 'It is an immense honour to count you among our guests.',
        'honour.html' => 'It is an <strong style="color:#ffd65a;">immense honour</strong> to count you among our guests.',
        'presence.text' => "Your presence at Seppo's 100% Live concert « Ndol'a Mbalè » is not a simple purchase: it is a gesture of trust and support for the artist, and we are deeply grateful to you. On December 30, we are preparing an evening worthy of this honour.",
        'presence.html' => "Your presence at Seppo's 100% Live concert « Ndol'a Mbalè » is not a simple purchase: it is a gesture of trust and support for the artist, and we are <strong style=\"color:#fff6ea;\">deeply grateful</strong> to you. On December 30, we are preparing an evening worthy of this honour.",
        'yourBooking' => 'YOUR BOOKING',
        'holder' => 'Ticket holder: {holder}',
        'category' => 'Category: {tier}',
        'numbersLine' => '{label}: {list}',
        'event' => 'THE EVENT',
        'question.text' => 'Any question? We remain entirely at your disposal at {phone}.',
        'question.html' => 'Any question? We remain entirely at your disposal at {phoneLink}.',
        'thanks' => 'Thank you for making this evening unforgettable.',
        'gratitude' => 'With all our gratitude,',
        'inTheNameOf' => 'In the name of',
        'dateLabel' => 'Date',
        'venueLabel' => 'Venue',
        'patronage.html' => 'Under the high patronage of <span style="color:#f2b705;">the Governor</span> of the Littoral Region',
        'posterAlt' => "Seppo – 100% Live Concert Ndol'a Mbalè",
        'footer' => "Seppo – 100% Live Concert « Ndol'a Mbalè »",
    ],
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Langue d'email valide (français par défaut). */
function email_lang(?string $lang): string
{
    return isset(EMAIL_TEXTS[$lang ?? '']) ? $lang : 'fr';
}

/**
 * Retourne ['subject' => ..., 'text' => ..., 'html' => ...]
 * $reserved = true : email de RÉSERVATION (paiement en attente de confirmation, pas de PDF)
 * $reserved = false : email des BILLETS (paiement confirmé, PDF joints)
 * $lang : 'fr' ou 'en' (langue choisie par le client sur le site)
 */
function build_tickets_email(string $holderRaw, string $tierId, array $numbers, string $siteUrl = '', bool $reserved = false, string $reference = '', string $lang = 'fr'): array
{
    $lang = email_lang($lang);
    $L = EMAIL_TEXTS[$lang];
    $C = EMAIL_CONTACT;
    $k = count($numbers) > 1 ? 'many' : 'one';
    $tr = fn (string $s, array $v = []) => strtr($s, $v);

    $tierRaw = TIERS[$tierId]['name'] ?? $L['ticketWord'];
    $color = TIER_COLORS[$tierId] ?? '#f2b705';
    $nums = array_map('format_ticket_number', $numbers);
    $firstRaw = preg_split('/\s+/u', trim($holderRaw))[0] ?? '';
    $numsList = implode(', ', $nums);

    $subject = $tr($L['subject.' . ($reserved ? 'reserved' : 'paid') . '.' . $k], ['{first}' => $firstRaw, '{n}' => count($numbers)]);
    $joined = $L['joined.' . $k];
    $ticketsLower = $L['yourTicketLower.' . $k];
    $mainLine = $reserved
        ? $tr($L['main.reserved'], ['{tickets}' => $ticketsLower])
        : $tr($L['main.paid'], ['{joined}' => $joined]);
    $mainIcon = $reserved ? '⏳' : '📎';
    $ticketsHeading = $reserved ? $L['reservedHeading.' . $k] : $L['yourTickets.' . $k];
    $refLine = $reference !== '' ? $tr($L['ref'], ['{ref}' => $reference]) : '';
    $preheader = $reserved
        ? $tr($L['preheader.reserved'], ['{tickets}' => $ticketsLower])
        : $tr($L['preheader.paid'], ['{joined}' => $joined]);
    $bullets = array_map(fn ($b) => $tr($b, ['{each}' => $L['each.' . $k]]), $L['bullets.' . ($reserved ? 'reserved' : 'paid')]);

    // ---------- Version texte (valeurs brutes)
    $textBullets = implode("\n", array_map(fn ($b) => "- $b", $bullets));
    $text = implode("\n", [
        $tr($L['greeting'], ['{first}' => $firstRaw]),
        '',
        $L['honour.text'],
        '',
        $L['presence.text'],
        '',
        $L['yourBooking'],
        $tr($L['holder'], ['{holder}' => $holderRaw]),
        $tr($L['category'], ['{tier}' => $tierRaw]),
        $tr($L['numbersLine'], ['{label}' => $L['ticketsLabel.' . $k], '{list}' => $numsList]),
        $refLine,
        '',
        $L['event'],
        $L['date'] . ' – ' . $L['doors'],
        $L['venue'],
        '',
        $mainLine,
        $textBullets,
        '',
        $tr($L['question.text'], ['{phone}' => $C['phone']]),
        '',
        $L['thanks'],
        $L['gratitude'],
        $C['signature'],
    ]);

    // ---------- Version HTML (valeurs échappées)
    $name = h($firstRaw);
    $fullName = h($holderRaw);
    $tier = h($tierRaw);
    $subjectHtml = h($subject);
    $preheaderHtml = h($preheader);
    $mainLineHtml = h($mainLine);
    $htmlBullets = implode("\n      ", array_map(fn ($b) => '<li>' . h($b) . '</li>', $bullets));
    $refHtml = $refLine !== '' ? '<div style="font-size:13px;color:#d9c2a6;margin-top:6px;">' . h($refLine) . '</div>' : '';
    $ticketsHeadingHtml = h($ticketsHeading);
    $greeting = h($tr($L['greeting'], ['{first}' => $firstRaw]));
    $phoneLink = '<a href="' . h($C['phoneHref']) . '" style="color:#f2b705;font-weight:bold;text-decoration:none;">' . h($C['phone']) . '</a>';
    $question = $tr(h($L['question.html']), [h('{phoneLink}') => $phoneLink, '{phoneLink}' => $phoneLink]);
    $date = h($L['date']);
    $doors = h($L['doors']);
    $venue = h($L['venue']);
    $signature = h($C['signature']);
    $chips = implode('', array_map(
        fn ($n) => '<span style="display:inline-block;margin:4px;padding:8px 14px;background:#ffffff;color:#000000;font:bold 20px Arial,Helvetica,sans-serif;border-radius:4px;">' . $n . '</span>',
        $nums
    ));
    $poster = $siteUrl ? h(rtrim($siteUrl, '/') . '/affiche-seppo.png') : '';
    $posterAlt = h($L['posterAlt']);
    $headerBlock = $poster
        ? <<<HTML
<tr><td><img src="{$poster}" width="600" alt="{$posterAlt}" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></td></tr>
HTML
        : <<<HTML
<tr><td style="background:#000000;border-bottom:2px solid #f2b705;padding:10px 16px;text-align:center;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#ffffff;">
    {$L['patronage.html']}
  </td></tr>

  <tr><td style="padding:32px 32px 8px;text-align:center;background:linear-gradient(180deg,#3a1808,#1a0d06);">
    <div style="font-size:30px;letter-spacing:6px;color:#ffffff;font-weight:300;">Seppo</div>
    <div style="font-size:40px;line-height:1;font-weight:bold;letter-spacing:1px;color:#ffffff;margin-top:4px;">CONCERT</div>
    <div style="display:inline-block;margin-top:8px;padding:3px 10px;background:#c8102e;color:#ffffff;font-weight:bold;font-style:italic;font-size:14px;">100% LIVE</div>
    <div style="font-size:24px;font-weight:bold;color:#f2b705;margin-top:10px;letter-spacing:1px;">NDOL'A MBALÈ</div>
  </td></tr>
HTML;
    $footer = h($L['footer']);

    $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<title>{$subjectHtml}</title>
</head>
<body style="margin:0;padding:0;background:#0d0704;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$preheaderHtml}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0d0704;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#1a0d06;border:1px solid #5a3a10;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#fff6ea;">

  {$headerBlock}

  <tr><td style="padding:24px 32px 8px;">
    <p style="margin:0 0 16px;font-size:20px;color:#ffd65a;font-weight:bold;">{$greeting}</p>
    <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">{$L['honour.html']}</p>
    <p style="margin:0 0 14px;font-size:16px;line-height:1.6;color:#e9d8c2;">{$L['presence.html']}</p>
  </td></tr>

  <tr><td style="padding:8px 32px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#2a1408;border:1px solid #5a3a10;border-left:6px solid {$color};border-radius:8px;">
      <tr><td style="padding:20px 22px;text-align:center;">
        <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#d9c2a6;">{$ticketsHeadingHtml}</div>
        <div style="display:inline-block;margin:10px 0 6px;padding:4px 12px;background:{$color};color:#120804;font-weight:bold;font-size:14px;letter-spacing:1px;text-transform:uppercase;">{$tier}</div>
        <div style="margin:6px 0;">{$chips}</div>
        <div style="font-size:14px;color:#e9d8c2;margin-top:6px;">{$L['inTheNameOf']} <strong style="color:#ffffff;">{$fullName}</strong></div>
        {$refHtml}
      </td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:16px 32px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td width="50%" valign="top" style="padding:12px;background:#120804;border-radius:8px 0 0 8px;">
          <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#f2b705;">{$L['dateLabel']}</div>
          <div style="font-size:15px;font-weight:bold;margin-top:4px;">{$date}</div>
          <div style="font-size:14px;color:#d9c2a6;">{$doors}</div>
        </td>
        <td width="50%" valign="top" style="padding:12px;background:#120804;border-radius:0 8px 8px 0;border-left:1px solid #3a2410;">
          <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#f2b705;">{$L['venueLabel']}</div>
          <div style="font-size:15px;font-weight:bold;margin-top:4px;">Falaise Diamond</div>
          <div style="font-size:14px;color:#d9c2a6;">Bonanjo, Douala</div>
        </td>
      </tr>
    </table>
  </td></tr>

  <tr><td style="padding:16px 32px 8px;">
    <p style="margin:0 0 10px;font-size:15px;line-height:1.6;">{$mainIcon} {$mainLineHtml}</p>
    <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#e9d8c2;">
      {$htmlBullets}
    </ul>
  </td></tr>

  <tr><td style="padding:20px 32px 8px;">
    <p style="margin:0;font-size:14px;line-height:1.6;color:#e9d8c2;">{$question}</p>
  </td></tr>

  <tr><td style="padding:24px 32px 32px;">
    <p style="margin:0 0 6px;font-size:16px;line-height:1.6;">{$L['thanks']}</p>
    <p style="margin:0;font-size:15px;line-height:1.6;color:#e9d8c2;">{$L['gratitude']}<br><strong style="color:#ffd65a;">{$signature}</strong></p>
  </td></tr>

  <tr><td style="background:#000000;padding:14px 16px;text-align:center;font-size:11px;color:#8a7760;">
    {$footer} · {$date} · {$venue}
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}
