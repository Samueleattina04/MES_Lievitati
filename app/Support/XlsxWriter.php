<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Scrittore XLSX/XLSM senza dipendenze (usa ZipArchive). Genera un pacchetto OOXML "completo"
 * (sharedStrings, styles, docProps, dimension) compatibile anche con motori severi come l'import
 * di Microsoft Access/ACE — che NON legge le stringhe inline. Supporta piu' fogli, stringhe e numeri.
 */
final class XlsxWriter
{
    /**
     * @param  list<array{name:string, rows:list<list<string|int|float|null>>}>  $fogli
     * @param  bool  $macroEnabled  Se true genera un .xlsm (workbook macro-enabled) invece di .xlsx.
     */
    public static function scrivi(array $fogli, bool $macroEnabled = false): string
    {
        if ($fogli === []) {
            $fogli = [['name' => 'Foglio1', 'rows' => []]];
        }
        $n = count($fogli);

        // Tabella delle stringhe condivise (sharedStrings): indice per valore.
        $sst = [];
        $sstList = [];
        $totStringhe = 0;

        $sheetXml = [];
        foreach ($fogli as $foglio) {
            $sheetXml[] = self::foglioXml((array) ($foglio['rows'] ?? []), $sst, $sstList, $totStringhe);
        }

        $tipoWorkbook = $macroEnabled
            ? 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Impossibile creare il file temporaneo xlsx.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file xlsx.');
        }

        // [Content_Types].xml
        $overrides = '';
        for ($i = 1; $i <= $n; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml',
            self::XMLHEAD
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$tipoWorkbook.'"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels',
            self::XMLHEAD
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>');

        // xl/workbook.xml + rels (fogli + styles + sharedStrings)
        $sheetsTag = '';
        $relsTag = '';
        for ($i = 1; $i <= $n; $i++) {
            $nome = self::nomeFoglio((string) ($fogli[$i - 1]['name'] ?? ('Foglio'.$i)));
            $sheetsTag .= '<sheet name="'.self::esc($nome).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $relsTag .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $relsTag .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $relsTag .= '<Relationship Id="rId'.($n + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $zip->addFromString('xl/workbook.xml',
            self::XMLHEAD
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetsTag.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            self::XMLHEAD
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relsTag.'</Relationships>');

        // Fogli
        for ($i = 1; $i <= $n; $i++) {
            $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', $sheetXml[$i - 1]);
        }

        // styles.xml (minimo ma valido)
        $zip->addFromString('xl/styles.xml',
            self::XMLHEAD
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');

        // sharedStrings.xml
        $si = '';
        foreach ($sstList as $s) {
            $si .= '<si><t xml:space="preserve">'.self::esc($s).'</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml',
            self::XMLHEAD
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$totStringhe.'" uniqueCount="'.count($sstList).'">'
            .$si.'</sst>');

        // docProps
        $zip->addFromString('docProps/core.xml',
            self::XMLHEAD
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>MES Lievitati</dc:creator><cp:lastModifiedBy>MES Lievitati</cp:lastModifiedBy>'
            .'</cp:coreProperties>');
        $zip->addFromString('docProps/app.xml',
            self::XMLHEAD
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>MES Lievitati</Application></Properties>');

        $zip->close();
        $bin = (string) file_get_contents($path);
        @unlink($path);

        return $bin;
    }

    private const XMLHEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

    /**
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array<string,int>  $sst
     * @param  list<string>  $sstList
     */
    private static function foglioXml(array $rows, array &$sst, array &$sstList, int &$totStringhe): string
    {
        $maxCol = 0;
        foreach ($rows as $row) {
            $maxCol = max($maxCol, count($row));
        }
        $nRows = count($rows);
        $dim = $nRows > 0 && $maxCol > 0 ? 'A1:'.self::colLetter($maxCol - 1).$nRows : 'A1';

        $xml = self::XMLHEAD
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dim.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<sheetData>';

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
                    $s = (string) $cell;
                    if (! isset($sst[$s])) {
                        $sst[$s] = count($sstList);
                        $sstList[] = $s;
                    }
                    $totStringhe++;
                    $xml .= '<c r="'.$ref.'" t="s"><v>'.$sst[$s].'</v></c>';
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
