<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Scrittore XLSX minimale senza dipendenze (usa ZipArchive). Supporta piu' fogli, stringhe (inline) e
 * numeri. Sufficiente per gli export tabellari del MES (es. tracciato Omni). Nessuno stile.
 */
final class XlsxWriter
{
    /**
     * @param  list<array{name:string, rows:list<list<string|int|float|null>>}>  $fogli
     */
    public static function scrivi(array $fogli): string
    {
        if ($fogli === []) {
            $fogli = [['name' => 'Foglio1', 'rows' => []]];
        }
        $n = count($fogli);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Impossibile creare il file temporaneo xlsx.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file xlsx.');
        }

        $overrides = '';
        for ($i = 1; $i <= $n; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $sheetsXml = '';
        $relsXml = '';
        for ($i = 1; $i <= $n; $i++) {
            $nome = self::nomeFoglio((string) ($fogli[$i - 1]['name'] ?? ('Foglio'.$i)));
            $sheetsXml .= '<sheet name="'.self::esc($nome).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $relsXml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetsXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relsXml.'</Relationships>');

        for ($i = 1; $i <= $n; $i++) {
            $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', self::foglioXml((array) ($fogli[$i - 1]['rows'] ?? [])));
        }

        $zip->close();
        $bin = (string) file_get_contents($path);
        @unlink($path);

        return $bin;
    }

    /** @param list<list<string|int|float|null>> $rows */
    private static function foglioXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $r = 0;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="'.$r.'">';
            $c = 0;
            foreach ($row as $cell) {
                $ref = self::colLetter($c).$r;
                $c++;
                if ($cell === null || $cell === '') {
                    $xml .= '<c r="'.$ref.'"/>';
                } elseif (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="'.$ref.'"><v>'.self::num($cell).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $cell).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function num(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    /** Lettera colonna Excel da indice 0-based (0->A, 26->AA). */
    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m).$s;
            $i = intdiv($i - 1, 26);
        }

        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function nomeFoglio(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'Foglio';
        }

        return mb_substr($name, 0, 31);
    }
}
