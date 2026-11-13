<?php

declare(strict_types=1);

namespace App\CarichiProduzione;

use App\CarichiProduzione\Contracts\CarichiProduzioneSourceInterface;

/**
 * Sorgente FINTA dei carichi di produzione per sviluppo/CI/test (§ Carichi): nessun DB. Restituisce
 * un piccolo insieme di carichi in memoria, applicando gli stessi filtri dell'adapter reale
 * (operatore creatore + CodOpMag) cosi' da esercitare la logica senza il gestionale.
 */
final class FixtureCarichiProduzioneAdapter implements CarichiProduzioneSourceInterface
{
    /** @var list<CaricoProduzione> */
    private array $carichi;

    /** @param list<CaricoProduzione>|null $carichi Se null usa un dataset di esempio. */
    public function __construct(?array $carichi = null)
    {
        $this->carichi = $carichi ?? self::esempi();
    }

    public function carichi(string $operatore, array $codOpMag, ?string $da = null, ?string $a = null): array
    {
        $operatore = trim($operatore);
        $codOpMag = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $codOpMag), static fn (string $v) => $v !== ''));
        $da = $da !== null && trim($da) !== '' ? trim($da) : null;
        $a = $a !== null && trim($a) !== '' ? trim($a) : null;

        $out = array_values(array_filter($this->carichi, static function (CaricoProduzione $c) use ($operatore, $codOpMag, $da, $a): bool {
            if ($operatore !== '' && mb_strtoupper($c->operatore) !== mb_strtoupper($operatore)) {
                return false;
            }
            if ($codOpMag !== [] && ! in_array($c->codOpMag, $codOpMag, true)) {
                return false;
            }
            // Data del carico in formato 'd/m/Y H.i.s' -> confronto su 'Y-m-d'.
            if (($da !== null || $a !== null) && $c->data !== null) {
                $dt = \DateTime::createFromFormat('d/m/Y H.i.s', $c->data);
                $giorno = $dt !== false ? $dt->format('Y-m-d') : null;
                if ($giorno !== null) {
                    if ($da !== null && $giorno < $da) {
                        return false;
                    }
                    if ($a !== null && $giorno > $a) {
                        return false;
                    }
                }
            }

            return true;
        }));

        usort($out, static fn (CaricoProduzione $a, CaricoProduzione $b) => (string) $a->data <=> (string) $b->data);

        return $out;
    }

    /** @return list<CaricoProduzione> */
    private static function esempi(): array
    {
        return [
            new CaricoProduzione(
                data: '10/11/2026 08.15.00', articolo: 'PAN0104', descrizione: 'Panettone classico 1kg',
                lotto: '6412-27826111', quantita: 320.0, um: 'PZ', magazzino: '01', lottoPf: '6412-27826111',
                causale: '103', codOpMag: '850', operatore: 'N.CELESTINO',
                famiglia: 'Lievitati', macrofamiglia: 'Dolci', microfamiglia: 'Panettoni',
            ),
            new CaricoProduzione(
                data: '10/11/2026 09.40.00', articolo: 'COL0201', descrizione: 'Colomba classica 750g',
                lotto: '7120-27826110', quantita: 180.0, um: 'PZ', magazzino: '01', lottoPf: '7120-27826110',
                causale: '103', codOpMag: '50', operatore: 'N.CELESTINO',
                famiglia: 'Lievitati', macrofamiglia: 'Dolci', microfamiglia: 'Colombe',
            ),
            new CaricoProduzione(
                data: '10/11/2026 11.05.00', articolo: 'TOR0301', descrizione: 'Torrone morbido 200g',
                lotto: '8030-27826110', quantita: 540.0, um: 'PZ', magazzino: '01', lottoPf: '8030-27826110',
                causale: '103', codOpMag: '850', operatore: 'M.ROSSI',
                famiglia: 'Torroni', macrofamiglia: 'Dolci', microfamiglia: 'Morbidi',
            ),
        ];
    }
}
