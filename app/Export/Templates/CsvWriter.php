<?php

declare(strict_types=1);

namespace App\Export\Templates;

/**
 * Helper CSV (separatore ';', quoting minimo) con BOM UTF-8 per compatibilita' Excel.
 */
final class CsvWriter
{
    /** @param list<list<mixed>> $righe */
    public static function scrivi(array $righe): string
    {
        $out = "\xEF\xBB\xBF"; // BOM UTF-8
        foreach ($righe as $riga) {
            $out .= implode(';', array_map([self::class, 'campo'], $riga))."\r\n";
        }

        return $out;
    }

    private static function campo(mixed $valore): string
    {
        $s = (string) $valore;
        if (str_contains($s, ';') || str_contains($s, '"') || str_contains($s, "\n")) {
            return '"'.str_replace('"', '""', $s).'"';
        }

        return $s;
    }
}
