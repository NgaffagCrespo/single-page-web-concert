<?php
// Génère data/ventes.xlsx (vrai fichier Excel) à partir du registre.
// Onglet « Ventes » : une ligne par billet. Onglet « Résumé » : totaux.
// Régénéré entièrement à chaque vente -> toujours à jour et cohérent.

function xml_escape(string $s): string
{
    // Retire les caractères de contrôle interdits en XML
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/**
 * $rows : liste de lignes ; chaque cellule = ['v' => valeur, 's' => style, 'f' => formule?]
 * styles : 0 normal, 1 en-tête, 2 montant, 3 total (gras), 4 montant total
 */
function sheet_xml(array $rows, array $widths): string
{
    $cols = '';
    foreach ($widths as $i => $w) {
        $n = $i + 1;
        $cols .= "<col min=\"$n\" max=\"$n\" width=\"$w\" customWidth=\"1\"/>";
    }
    $data = '';
    foreach ($rows as $r => $cells) {
        $rn = $r + 1;
        $data .= "<row r=\"$rn\">";
        foreach ($cells as $c => $cell) {
            $ref = col_letter($c) . $rn;
            $s = $cell['s'] ?? 0;
            if (isset($cell['f'])) {
                $data .= "<c r=\"$ref\" s=\"$s\"><f>" . xml_escape($cell['f']) . '</f></c>';
            } elseif (is_int($cell['v']) || is_float($cell['v'])) {
                $data .= "<c r=\"$ref\" s=\"$s\"><v>{$cell['v']}</v></c>";
            } else {
                $data .= "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xml_escape((string) $cell['v']) . '</t></is></c>';
            }
        }
        $data .= '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . "<cols>$cols</cols><sheetData>$data</sheetData></worksheet>";
}

function write_sales_xlsx(array $db, string $path): void
{
    $h = fn ($v) => ['v' => $v, 's' => 1];

    // --- Onglet Ventes : une ligne par billet, du plus ancien au plus récent
    $orders = $db['orders'];
    uasort($orders, fn ($a, $b) => strcmp($a['allocatedAt'], $b['allocatedAt']));
    // Colonnes : A N° | B Gamme | C Montant | D Paiement | E Statut | F Nom | G Téléphone | H Email | I Commande | J Date de commande | K Entrée validée le | L Annulation | M Paiement confirmé le
    $sales = [[$h('N° billet'), $h('Gamme'), $h('Montant (FCFA)'), $h('Paiement'), $h('Statut'), $h('Nom'), $h('Téléphone'), $h('Email'), $h('Commande'), $h('Date de commande'), $h('Entrée validée le'), $h('Annulation'), $h('Paiement confirmé le')]];
    $paid = $db['paid'] ?? [];
    $refused = $db['refused'] ?? [];
    $checkins = $db['checkins'] ?? [];
    $fmtDate = fn ($iso) => (new DateTime($iso))->setTimezone(new DateTimeZone('Africa/Douala'))->format('d/m/Y H:i');
    foreach ($orders as $id => $o) {
        $tier = TIERS[$o['tierId']] ?? ['name' => $o['tierId'], 'price' => 0];
        $date = $fmtDate($o['allocatedAt']);
        foreach ($o['numbers'] as $n) {
            $sales[] = [
                ['v' => format_ticket_number($n)], ['v' => $tier['name']], ['v' => $tier['price'], 's' => 2],
                ['v' => is_manual($o) ? 'Espèces' : 'En ligne'], ['v' => ticket_status($db, $o, $n)],
                ['v' => $o['holder']], ['v' => $o['phone'] ?? ''], ['v' => $o['email'] ?? ''],
                ['v' => $id], ['v' => $date],
                ['v' => isset($checkins[$n]) ? $fmtDate($checkins[$n]['at']) : ''],
                ['v' => isset($refused[$n]) ? $fmtDate($refused[$n]['at']) . ' – ' . $refused[$n]['reason'] : ''],
                ['v' => isset($paid[$n]) ? $fmtDate($paid[$n]['at']) : ''],
            ];
        }
    }

    // --- Onglet Résumé : formules (se recalculent si on modifie l'onglet Ventes)
    // Les billets annulés (REFUSED) sont exclus des totaux et comptés à part
    // Billets vendus = ni RESERVED ni REFUSED
    $notRefused = 'Ventes!E:E,"<>' . STATUS_REFUSED . '",Ventes!E:E,"<>' . STATUS_RESERVED . '"';
    $summary = [[$h('Gamme'), $h('Billets vendus'), $h('Montant total (FCFA)')]];
    $r = 2;
    foreach (TIERS as $t) {
        $summary[] = [
            ['v' => $t['name']],
            ['f' => "COUNTIFS(Ventes!B:B,A$r,$notRefused)"],
            ['f' => "SUMIFS(Ventes!C:C,Ventes!B:B,A$r,$notRefused)", 's' => 2],
        ];
        $r++;
    }
    $last = $r - 1;
    $summary[] = [['v' => 'TOTAL', 's' => 3], ['f' => "SUM(B2:B$last)", 's' => 3], ['f' => "SUM(C2:C$last)", 's' => 4]];

    // Répartition par mode de paiement et entrées validées
    $summary[] = [['v' => '']];
    $summary[] = [$h('Paiement'), $h('Billets'), $h('Montant (FCFA)')];
    foreach (['En ligne', 'Espèces'] as $mode) {
        $rr = count($summary) + 1;
        $summary[] = [
            ['v' => $mode],
            ['f' => "COUNTIFS(Ventes!D:D,A$rr,$notRefused)"],
            ['f' => "SUMIFS(Ventes!C:C,Ventes!D:D,A$rr,$notRefused)", 's' => 2],
        ];
    }
    $summary[] = [['v' => '']];
    $summary[] = [['v' => 'Entrées validées', 's' => 3], ['f' => 'COUNTIF(Ventes!E:E,"' . STATUS_ENTERED . '")', 's' => 3]];
    $summary[] = [
        ['v' => 'Réservés, à confirmer (RESERVED)', 's' => 3],
        ['f' => 'COUNTIF(Ventes!E:E,"' . STATUS_RESERVED . '")', 's' => 3],
        ['f' => 'SUMIF(Ventes!E:E,"' . STATUS_RESERVED . '",Ventes!C:C)', 's' => 4],
    ];
    $summary[] = [
        ['v' => 'Billets annulés (REFUSED)', 's' => 3],
        ['f' => 'COUNTIF(Ventes!E:E,"' . STATUS_REFUSED . '")', 's' => 3],
        ['f' => 'SUMIF(Ventes!E:E,"' . STATUS_REFUSED . '",Ventes!C:C)', 's' => 4],
    ];

    // --- Onglet Annulations : billets annulés dont le numéro a été réattribué (historique)
    $cancel = [[$h('N° billet'), $h('Gamme'), $h('Montant (FCFA)'), $h('Ancien titulaire'), $h('Téléphone'), $h('Email'), $h('Commande'), $h('Annulé le'), $h('Motif'), $h('Numéro réattribué le')]];
    foreach ($orders as $id => $o) {
        $tier = TIERS[$o['tierId']] ?? ['name' => $o['tierId'], 'price' => 0];
        foreach ($o['released'] ?? [] as $r) {
            $cancel[] = [
                ['v' => format_ticket_number($r['number'])], ['v' => $tier['name']], ['v' => $tier['price'], 's' => 2],
                ['v' => $o['holder']], ['v' => $o['phone'] ?? ''], ['v' => $o['email'] ?? ''], ['v' => $id],
                ['v' => $fmtDate($r['refusedAt'])], ['v' => $r['reason']], ['v' => $fmtDate($r['releasedAt'])],
            ];
        }
    }

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0 &quot;FCFA&quot;"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2B705"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2C2"/></patternFill></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf xfId="0"/>'
        . '<xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/>'
        . '<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'
        . '<xf xfId="0" fontId="1" fillId="3" applyFont="1" applyFill="1"/>'
        . '<xf xfId="0" numFmtId="164" fontId="1" fillId="3" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Ventes" sheetId="1" r:id="rId1"/><sheet name="Résumé" sheetId="2" r:id="rId2"/><sheet name="Annulations" sheetId="3" r:id="rId4"/></sheets>'
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => sheet_xml($sales, [11, 12, 16, 11, 17, 28, 17, 30, 16, 18, 18, 34, 20]),
        'xl/worksheets/sheet2.xml' => sheet_xml($summary, [26, 16, 22]),
        'xl/worksheets/sheet3.xml' => sheet_xml($cancel, [11, 12, 16, 28, 17, 30, 16, 18, 34, 20]),
    ];

    $tmp = $path . '.tmp';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Impossible de créer le fichier Excel.');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    rename($tmp, $path); // remplace l'ancien fichier en une fois
}
