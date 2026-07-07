<?php

declare(strict_types=1);

/*
 * Generatore dei fixture di distinta usati da FixtureBomAdapter (sviluppo/test).
 *
 * Definisce la ricetta ASSPAN01 (§3, §15) in forma compatta padre->figli con QtaComponente
 * per lotto di riferimento (QtaRifDb) e la "esplode" con le STESSE semantiche della query SQL
 * reale (§4.3):
 *   - normalizzazione: qtaCumulataFiglio = qtaCumulataPadre * (QtaComponente / QtaRifDbPadre);
 *   - i sottoalberi dei nodi condivisi sono duplicati per ogni percorso (come la CTE ricorsiva);
 *   - protezione anti-loop per percorso.
 *
 * NB: le quantita' sono rappresentative/coerenti, non i valori reali del gestionale
 * (qui non disponibile). Servono a validare struttura e calcoli, non i numeri di produzione.
 *
 * Uso:  php tests/fixtures/bom/_src/build_fixtures.php
 */

/** @var array<string, array{desc:string, udm:string, qtaRif:float, children:list<array{0:string,1:float}>}> */
$produced = [
    'ASSPAN01' => ['desc' => 'Assortimento panettoni 6+6 gusti', 'udm' => 'PZ', 'qtaRif' => 1, 'children' => [
        ['PAN0104', 6], ['PAN0136', 6],
    ]],
    'PAN0104' => ['desc' => 'Panettone pistacchio, ananas e albicocca', 'udm' => 'PZ', 'qtaRif' => 1, 'children' => [
        ['PANPIST/ANANAS/ALB750', 1], ['INCARTO0104', 1], ['NASTRO01', 1], ['PENDAGLIO01', 1], ['ETICH0104A', 1], ['ETICH0104B', 1],
    ]],
    'PAN0136' => ['desc' => 'Panettone pistacchio, pesca e gocce di cioccolato', 'udm' => 'PZ', 'qtaRif' => 1, 'children' => [
        ['PANPIST/PESCA/CIOC750', 1], ['INCARTO0136', 1], ['NASTRO01', 1], ['PENDAGLIO01', 1], ['ETICH0136A', 1], ['ETICH0136B', 1],
    ]],
    'PANPIST/ANANAS/ALB750' => ['desc' => 'Semilavorato panettone gusto ananas/albicocca 750g', 'udm' => 'PZ', 'qtaRif' => 1, 'children' => [
        ['IMPASTOTRADPIST/AN/ALB', 0.75], ['BUSTA-PP', 1], ['ALCOOL', 0.1],
    ]],
    'PANPIST/PESCA/CIOC750' => ['desc' => 'Semilavorato panettone gusto pesca/cioccolato 750g', 'udm' => 'PZ', 'qtaRif' => 1, 'children' => [
        ['IMPASTOTRADPIST/PESC/CIOC', 0.75], ['BUSTA-PP', 1], ['ALCOOL', 0.1],
    ]],
    // qtaRif=10: le QtaComponente sono espresse per un lotto di 10 kg di impasto tradizionale.
    'IMPASTOTRADPIST/AN/ALB' => ['desc' => 'Impasto tradizionale gusto ananas/albicocca', 'udm' => 'KG', 'qtaRif' => 10, 'children' => [
        ['IMPASTOCOLOMBE/PANETTONI', 8], ['ANANAS-CAND', 4.5], ['ALBICOCCA-CUB', 4.5], ['PISTACCHIO', 2.25],
        ['GLASSA', 9], ['ZUCCHERO-GRAN', 3], ['ALBUME', 2], ['OLIO-GIRA', 1], ['AROMA-VAN', 0.5], ['VINO-ZIB', 0.5],
        ['BURRO-P', 0],   // caso limite §3: stesso articolo in piu' fasi con quantita' 0
        ['PT0LI25', 1],   // caso §3: farina PT0LI25 presente a livelli diversi (qui liv.4, e sotto la base liv.5)
    ]],
    'IMPASTOTRADPIST/PESC/CIOC' => ['desc' => 'Impasto tradizionale gusto pesca/cioccolato', 'udm' => 'KG', 'qtaRif' => 10, 'children' => [
        ['IMPASTOCOLOMBE/PANETTONI', 8], ['PESCA-CAND', 4.5], ['CIOCC-GOCCE', 4.5], ['PISTACCHIO', 2.25],
        ['GLASSA', 9], ['ZUCCHERO-GRAN', 3], ['ALBUME', 2], ['OLIO-GIRA', 1],
    ]],
    // qtaRif=100: QtaComponente per un lotto di 100 kg di impasto base. Nodo CONDIVISO in ASSPAN01.
    'IMPASTOCOLOMBE/PANETTONI' => ['desc' => 'Impasto base colombe/panettoni (madre)', 'udm' => 'KG', 'qtaRif' => 100, 'children' => [
        ['ZUCCHERO-SEM', 25], ['BURRO-P', 20], ['TUORLO', 10], ['PT0LI25', 30], ['MIELE', 3], ['SALE', 1],
        ['MADRE492', 15], ['SCIROPPO-GLU', 5], ['LATTE-POLV', 4], ['AROMA-PAN', 1], ['ENZIMI', 0.5], ['ACQUA', 12.5],
        ['LIEVITO', 2], ['EMULSIONANTE', 1], ['VANIGLIA-BACC', 0.5], ['SCORZA-ARANCIA', 1.5], ['UVETTA', 5],
    ]],
];

/** @var array<string, array{desc:string, udm:string}> */
$leaves = [
    'INCARTO0104' => ['desc' => 'Incarto panettone gusto 1', 'udm' => 'PZ'],
    'INCARTO0136' => ['desc' => 'Incarto panettone gusto 2', 'udm' => 'PZ'],
    'NASTRO01' => ['desc' => 'Nastro decorativo', 'udm' => 'PZ'],
    'PENDAGLIO01' => ['desc' => 'Pendaglio', 'udm' => 'PZ'],
    'ETICH0104A' => ['desc' => 'Etichetta fronte PAN0104', 'udm' => 'PZ'],
    'ETICH0104B' => ['desc' => 'Etichetta retro PAN0104', 'udm' => 'PZ'],
    'ETICH0136A' => ['desc' => 'Etichetta fronte PAN0136', 'udm' => 'PZ'],
    'ETICH0136B' => ['desc' => 'Etichetta retro PAN0136', 'udm' => 'PZ'],
    'BUSTA-PP' => ['desc' => 'Busta polipropilene', 'udm' => 'PZ'],
    'ALCOOL' => ['desc' => 'Alcool alimentare', 'udm' => 'L'],
    'ANANAS-CAND' => ['desc' => 'Ananas candita', 'udm' => 'KG'],
    'ALBICOCCA-CUB' => ['desc' => 'Cubetti di albicocca', 'udm' => 'KG'],
    'PESCA-CAND' => ['desc' => 'Pesca candita', 'udm' => 'KG'],
    'CIOCC-GOCCE' => ['desc' => 'Gocce di cioccolato', 'udm' => 'KG'],
    'PISTACCHIO' => ['desc' => 'Pistacchio', 'udm' => 'KG'],
    'GLASSA' => ['desc' => 'Glassa', 'udm' => 'KG'],
    'ZUCCHERO-GRAN' => ['desc' => 'Zucchero in granella', 'udm' => 'KG'],
    'ALBUME' => ['desc' => "Albume d'uovo", 'udm' => 'KG'],
    'OLIO-GIRA' => ['desc' => 'Olio di girasole', 'udm' => 'L'],
    'AROMA-VAN' => ['desc' => 'Aroma vaniglia', 'udm' => 'KG'],
    'VINO-ZIB' => ['desc' => 'Vino zibibbo', 'udm' => 'L'],
    'ZUCCHERO-SEM' => ['desc' => 'Zucchero semolato', 'udm' => 'KG'],
    'BURRO-P' => ['desc' => 'Burro 82%', 'udm' => 'KG'],
    'TUORLO' => ['desc' => "Tuorlo d'uovo", 'udm' => 'KG'],
    'PT0LI25' => ['desc' => 'Farina PT0LI25', 'udm' => 'KG'],
    'MIELE' => ['desc' => 'Miele', 'udm' => 'KG'],
    'SALE' => ['desc' => 'Sale', 'udm' => 'KG'],
    'MADRE492' => ['desc' => 'Lievito madre 492', 'udm' => 'KG'],
    'SCIROPPO-GLU' => ['desc' => 'Sciroppo di glucosio', 'udm' => 'KG'],
    'LATTE-POLV' => ['desc' => 'Latte in polvere', 'udm' => 'KG'],
    'AROMA-PAN' => ['desc' => 'Aroma panettone', 'udm' => 'KG'],
    'ENZIMI' => ['desc' => 'Enzimi', 'udm' => 'KG'],
    'ACQUA' => ['desc' => 'Acqua', 'udm' => 'L'],
    'LIEVITO' => ['desc' => 'Lievito', 'udm' => 'KG'],
    'EMULSIONANTE' => ['desc' => 'Emulsionante', 'udm' => 'KG'],
    'VANIGLIA-BACC' => ['desc' => 'Vaniglia in bacche', 'udm' => 'KG'],
    'SCORZA-ARANCIA' => ['desc' => "Scorza d'arancia", 'udm' => 'KG'],
    'UVETTA' => ['desc' => 'Uvetta', 'udm' => 'KG'],
];

$posizione = 0;

/**
 * Esplode ricorsivamente producendo righe piatte con quantita' cumulate normalizzate.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<string> $path
 */
function walk(string $code, float $cumulated, int $livello, array $path, array &$rows, array $produced, array $leaves, int &$posizione): void
{
    $node = $produced[$code];
    foreach ($node['children'] as [$childCode, $qtaComp]) {
        $childCum = $cumulated * ($qtaComp / $node['qtaRif']);
        $isProdotto = isset($produced[$childCode]);
        $meta = $produced[$childCode] ?? $leaves[$childCode] ?? ['desc' => null, 'udm' => null];

        $rows[] = [
            'livello' => $livello,
            'articolo' => $childCode,
            'articolo_padre' => $code,
            'descrizione' => $meta['desc'] ?? null,
            'udm' => $meta['udm'] ?? null,
            'qta_per_unita' => round($childCum, 6),
            'is_prodotto' => $isProdotto,
            'posizione' => ++$posizione,
        ];

        // Duplica il sottoalbero per ogni percorso (come la ricorsione SQL); anti-loop per percorso.
        if ($isProdotto && ! in_array($childCode, $path, true)) {
            walk($childCode, $childCum, $livello + 1, [...$path, $childCode], $rows, $produced, $leaves, $posizione);
        }
    }
}

function build(string $root, array $produced, array $leaves): array
{
    $posizione = 0;
    $node = $produced[$root];
    $rows = [[
        'livello' => 0,
        'articolo' => $root,
        'articolo_padre' => null,
        'descrizione' => $node['desc'],
        'udm' => $node['udm'],
        'qta_per_unita' => 1.0,
        'is_prodotto' => true,
        'posizione' => $posizione,
    ]];
    walk($root, 1.0, 1, [$root], $rows, $produced, $leaves, $posizione);

    return ['articolo_radice' => $root, 'righe' => $rows];
}

$outDir = dirname(__DIR__);
foreach (['ASSPAN01', 'PAN0104'] as $root) {
    $data = build($root, $produced, $leaves);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($outDir.DIRECTORY_SEPARATOR.$root.'.json', $json.PHP_EOL);
    echo "Scritto {$root}.json (".count($data['righe'])." righe)".PHP_EOL;
}
