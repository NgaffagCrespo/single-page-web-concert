<?php
// Génération des billets PDF côté serveur (envoyés par email quand l'organisateur
// confirme le paiement). Même mise en page que le générateur du site :
// modèle de la gamme (public/templates/*.jpg), numéro dans le cadre blanc en
// haut à droite, nom du titulaire dessous, QR code signé sous le nom.
// Sans dépendance à l'extension GD (modèles JPEG, QR dessiné en vectoriel).
require_once __DIR__ . '/../vendor/autoload.php';

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

const PDF_W = 200;          // mm (modèles 1280 x 548 px)
const PDF_H = 85.625;
const PDF_NUMBER = ['x' => 185, 'y' => 18.4, 'size' => 15, 'boxW' => 26, 'boxH' => 6.6];
const PDF_HOLDER = ['x' => 181, 'y' => 30, 'size' => 9, 'maxWidth' => 30];
const PDF_QR = ['x' => 169, 'y' => 38, 'size' => 25, 'pad' => 1.5];

/** FPDF n'accepte que du Windows-1252 avec les polices standard. */
function pdf_text(string $s): string
{
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
}

/** Découpe un texte en lignes de largeur maximale (au plus $maxLines lignes). */
function pdf_wrap(FPDF $pdf, string $text, float $maxWidth, int $maxLines): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $try = $line === '' ? $word : "$line $word";
        if ($line !== '' && $pdf->GetStringWidth($try) > $maxWidth) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return array_slice($lines, 0, $maxLines);
}

/** Dessine un QR code (vectoriel) dans un carré de côté $size. */
function pdf_qr(FPDF $pdf, string $payload, float $x, float $y, float $size): void
{
    $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
    $count = $matrix->getWidth();
    $m = $size / $count;
    $pdf->SetFillColor(0, 0, 0);
    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if ($matrix->get($col, $row) === 1) {
                $pdf->Rect($x + $col * $m, $y + $row * $m, $m + 0.02, $m + 0.02, 'F'); // léger recouvrement : pas de fines lignes blanches
            }
        }
    }
}

/**
 * PDF des billets d'une commande (une page par billet).
 * $numbers : billets à inclure. Retourne le contenu du PDF.
 */
function build_tickets_pdf(array $config, array $order, array $numbers, string $orderId): string
{
    $pdf = new FPDF('L', 'mm', [PDF_H, PDF_W]);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetTitle(pdf_text('Billets Seppo – Ndol\'a Mbalè'));
    $template = __DIR__ . '/../../templates/' . basename($order['tierId']) . '.jpg';

    foreach ($numbers as $n) {
        $pdf->AddPage();
        if (is_file($template)) {
            $pdf->Image($template, 0, 0, PDF_W, PDF_H, 'JPG');
        } else {
            $pdf->SetFillColor(20, 10, 6);
            $pdf->Rect(0, 0, PDF_W, PDF_H, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Helvetica', 'B', 24);
            $pdf->SetXY(12, 20);
            $pdf->Cell(140, 12, pdf_text('SEPPO – ' . (TIERS[$order['tierId']]['name'] ?? '')));
        }

        // Numéro dans un cadre blanc (taille réduite si nécessaire pour tenir)
        $N = PDF_NUMBER;
        $label = format_ticket_number($n);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($N['x'] - $N['boxW'] / 2, $N['y'] - $N['boxH'] / 2, $N['boxW'], $N['boxH'], 'F');
        $pdf->SetTextColor(0, 0, 0);
        $size = $N['size'];
        $pdf->SetFont('Helvetica', 'B', $size);
        while ($pdf->GetStringWidth($label) > $N['boxW'] - 2 && $size > 8) {
            $pdf->SetFontSize(--$size);
        }
        $pdf->SetXY($N['x'] - $N['boxW'] / 2, $N['y'] - $N['boxH'] / 2);
        $pdf->Cell($N['boxW'], $N['boxH'], $label, 0, 0, 'C');

        // Nom du titulaire (2 lignes au plus)
        $H = PDF_HOLDER;
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', $H['size']);
        $lineH = $H['size'] * 0.3528 * 1.15;
        $lines = pdf_wrap($pdf, pdf_text(mb_strtoupper($order['holder'])), $H['maxWidth'], 2);
        foreach ($lines as $k => $line) {
            $pdf->SetXY($H['x'] - $H['maxWidth'] / 2 - 5, $H['y'] - 2.6 + $k * $lineH);
            $pdf->Cell($H['maxWidth'] + 10, $lineH, $line, 0, 0, 'C');
        }

        // QR code signé, sur fond blanc
        $Q = PDF_QR;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($Q['x'] - $Q['pad'], $Q['y'] - $Q['pad'], $Q['size'] + 2 * $Q['pad'], $Q['size'] + 2 * $Q['pad'], 'F');
        pdf_qr($pdf, ticket_qr_payload($config, $n, $orderId), $Q['x'], $Q['y'], $Q['size']);
    }
    return $pdf->Output('S');
}
