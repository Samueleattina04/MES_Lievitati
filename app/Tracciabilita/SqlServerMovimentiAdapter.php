<?php

declare(strict_types=1);

namespace App\Tracciabilita;

use App\Tracciabilita\Contracts\MovimentiLottoSourceInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Adapter di PRODUZIONE verso ESOLVER (SQL Server), SOLA LETTURA: movimenti di magazzino per lotto
 * (§6-bis). Unisce `MovimMagLotto` (dettaglio lotto: articolo, lotto, lotto del prodotto) a
 * `MovimMagazzino` (testata: data, tipo movimento, causale) su DBGruppo+IdDocumento+IdRigaDoc+IdRigaMag.
 * Carico/scarico distinti da `TipoMovMag` (2/3 da config). WITH (NOLOCK) ovunque, nessuna scrittura.
 */
final class SqlServerMovimentiAdapter implements MovimentiLottoSourceInterface
{
    private int $tipoCarico;

    private int $tipoScarico;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly ConnectionInterface $connection,
        array $config = [],
    ) {
        $this->tipoCarico = (int) ($config['tipo_mov_carico'] ?? 2);
        $this->tipoScarico = (int) ($config['tipo_mov_scarico'] ?? 3);
    }

    public function consumiPerProdotti(array $lottiProdotto): array
    {
        return $this->interroga($lottiProdotto, 'ML.RifLottoPFAlfanum', $this->tipoScarico, 'scarico');
    }

    public function carichiPerLotti(array $lotti): array
    {
        return $this->interroga($lotti, 'ML.RifLottoAlfanum', $this->tipoCarico, 'carico');
    }

    /**
     * @param  list<string>  $lotti
     * @return list<MovimentoLotto>
     */
    private function interroga(array $lotti, string $colonnaFiltro, int $tipoMov, string $tipo): array
    {
        $lotti = array_values(array_unique(array_filter(
            array_map(static fn ($l) => trim((string) $l), $lotti),
            static fn (string $l) => $l !== '',
        )));

        if ($lotti === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($lotti, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = <<<SQL
            SELECT
                ML.CodArt          AS Articolo,
                ML.RifLottoAlfanum AS Lotto,
                ML.Quantita        AS Quantita,
                ML.Um              AS Um,
                ML.CodMag          AS Magazzino,
                ML.RifLottoPFAlfanum AS LottoProdotto,
                ML.CodArtPF        AS ArticoloProdotto,
                MM.DataRegistrazione AS Data,
                MM.CausaleMag      AS Causale
            FROM MovimMagLotto ML WITH (NOLOCK)
            INNER JOIN MovimMagazzino MM WITH (NOLOCK)
                ON  MM.DBGruppo    = ML.DBGruppo
                AND MM.IdDocumento = ML.IdDocumento
                AND MM.IdRigaDoc   = ML.IdRigaDoc
                AND MM.IdRigaMag   = ML.IdRigaMag
            WHERE {$colonnaFiltro} IN ({$ph})
              AND MM.TipoMovMag = ?
            ORDER BY MM.DataRegistrazione, ML.CodArt
            SQL;

            $rows = $this->connection->select($sql, [...$chunk, $tipoMov]);
            foreach ($rows as $r) {
                $r = (array) $r;
                $out[] = new MovimentoLotto(
                    tipo: $tipo,
                    articolo: (string) $r['Articolo'],
                    lotto: trim((string) $r['Lotto']),
                    quantita: (float) $r['Quantita'],
                    um: (string) $r['Um'],
                    magazzino: (string) $r['Magazzino'],
                    data: $this->dataIt($r['Data']),
                    causale: (string) $r['Causale'],
                    lottoProdotto: trim((string) ($r['LottoProdotto'] ?? '')),
                    articoloProdotto: (string) ($r['ArticoloProdotto'] ?? ''),
                );
            }
        }

        return $out;
    }

    private function dataIt(mixed $valore): ?string
    {
        if ($valore === null || $valore === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $valore)->format('d/m/Y H.i.s');
        } catch (\Throwable) {
            return (string) $valore;
        }
    }
}
