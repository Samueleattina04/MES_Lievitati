<?php

declare(strict_types=1);

namespace App\CarichiProduzione;

use App\CarichiProduzione\Contracts\CarichiProduzioneSourceInterface;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Carichi di produzione da ESOLVER (SQL Server), SOLA LETTURA (§ Carichi). Parte da MovimMagazzino
 * (MM), che porta gia' articolo, quantita', UM, magazzino, data, causale e CodOpMag, e vi aggancia:
 *
 *   MovimMagazzino (MM)                                   -- CodOpMag (50/850), data, qta, causale
 *     ⋈ MovimMagLotto (ML)  (DBGruppo+IdDocumento+IdRigaDoc+IdRigaMag)  -- lotto e lotto PF
 *     ⋈ (opz.) testata documento (D)  (DBGruppo+IdDocumento)           -- FirmaCreazOperatore
 *     ⋈ ArtAnagrafica (A)   (DBGruppo+CodArt)                          -- descrizione + codici famiglia
 *     ⋈ (opz.) tabelle FAMIGLIA                                        -- descrizioni famiglia/macro/micro
 *
 * Filtri: CodOpMag IN (...) e (se configurata la testata documento) FirmaCreazOperatore = operatore.
 * Famiglie: ogni livello puo' mostrare direttamente un codice di ArtAnagrafica (art_col) oppure la
 * descrizione da una tabella di lookup (tabella+art_key+key+descr, join anche su DBGruppo). Finche'
 * non configurate restano colonne vuote. Tutto parametrizzato in config/mes.php. WITH (NOLOCK).
 */
final class SqlServerCarichiProduzioneAdapter implements CarichiProduzioneSourceInterface
{
    /** Slot famiglia (chiave config/DTO) => etichetta colonna nell'output. */
    private const SLOT_FAMIGLIE = [
        'famiglia' => 'Famiglia',
        'macrofamiglia' => 'Macrofamiglia',
        'microfamiglia' => 'Microfamiglia',
    ];

    /** @var array<string,mixed> */
    private array $cfg;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly ConnectionInterface $connection,
        array $config = [],
    ) {
        $this->cfg = $config;
    }

    public function carichi(string $operatore, array $codOpMag): array
    {
        $doc = (array) ($this->cfg['doc'] ?? []);
        $mm = (array) ($this->cfg['movim'] ?? []);
        $ml = (array) ($this->cfg['movim_lotto'] ?? []);
        $art = (array) ($this->cfg['articoli'] ?? []);
        $famiglie = (array) ($this->cfg['famiglie'] ?? []);

        // MovimMagazzino (testata movimento): sorgente principale.
        $tabMm = $this->ident((string) ($mm['tabella'] ?? 'MovimMagazzino'));
        $colCodOpMag = $this->ident((string) ($mm['col_cod_op_mag'] ?? 'CodOpMag'));
        $colData = $this->ident((string) ($mm['col_data'] ?? 'DataRegistrazione'));
        $colCausale = $this->ident((string) ($mm['col_causale'] ?? 'CausaleMag'));
        $colMmCodice = $this->ident((string) ($mm['col_codice'] ?? 'CodArt'));
        $colQta = $this->ident((string) ($mm['col_quantita'] ?? 'QuantitaUmMag'));
        $colUm = $this->ident((string) ($mm['col_um'] ?? 'UmMag'));
        $colMag = $this->ident((string) ($mm['col_magazzino'] ?? 'CodMag'));
        $joinMl = $this->colonne((array) ($mm['join_lotto'] ?? ['DBGruppo', 'IdDocumento', 'IdRigaDoc', 'IdRigaMag']));

        // MovimMagLotto: solo per il lotto (e lotto PF).
        $tabMl = $this->ident((string) ($ml['tabella'] ?? 'MovimMagLotto'));
        $colLotto = $this->ident((string) ($ml['col_lotto'] ?? 'RifLottoAlfanum'));
        $colLottoPf = $this->ident((string) ($ml['col_lotto_pf'] ?? 'RifLottoPFAlfanum'));

        // Anagrafica articoli: descrizione (e chiave per le famiglie).
        $tabArt = $this->ident((string) ($art['tabella'] ?? 'ArtAnagrafica'));
        $colArtCod = $this->ident((string) ($art['col_codice'] ?? 'CodArt'));
        $colArtDescr = $this->ident((string) ($art['col_descrizione'] ?? 'DesArt'));

        $on = static fn (string $a, string $b, array $cols): string => implode(
            ' AND ',
            array_map(static fn (string $c) => "{$a}.{$c} = {$b}.{$c}", $cols),
        );

        // SELECT di base (articolo/quantita'/UM/magazzino da MM; lotto da ML).
        $select = [
            "MM.{$colData} AS Data",
            "MM.{$colCodOpMag} AS CodOpMag",
            "MM.{$colCausale} AS Causale",
            "MM.{$colMmCodice} AS Articolo",
            "A.{$colArtDescr} AS Descrizione",
            "ML.{$colLotto} AS Lotto",
            "ML.{$colLottoPf} AS LottoPf",
            "MM.{$colQta} AS Quantita",
            "MM.{$colUm} AS Um",
            "MM.{$colMag} AS Magazzino",
        ];

        $joins = "\n            LEFT JOIN {$tabMl} ML WITH (NOLOCK) ON {$on('ML', 'MM', $joinMl)}";

        // Testata documento (opzionale): porta l'operatore creatore. Se non configurata -> colonna vuota.
        $tabDoc = trim((string) ($doc['tabella'] ?? ''));
        $colOperatore = (string) ($doc['col_operatore'] ?? 'FirmaCreazOperatore');
        $joinDoc = (array) ($doc['join'] ?? ['DBGruppo', 'IdDocumento']);
        $docAttiva = $tabDoc !== '';
        if ($docAttiva) {
            $joins .= "\n            LEFT JOIN {$this->ident($tabDoc)} D WITH (NOLOCK) ON "
                .$on('D', 'MM', $this->colonne($joinDoc));
            $select[] = "D.{$this->ident($colOperatore)} AS Operatore";
        } else {
            $select[] = "'' AS Operatore";
        }

        // Anagrafica (DBGruppo + CodArt).
        $joins .= "\n            LEFT JOIN {$tabArt} A WITH (NOLOCK) ON A.DBGruppo = MM.DBGruppo AND A.{$colArtCod} = MM.{$colMmCodice}";

        // Famiglie (opzionali). Ogni livello, nell'ordine famiglia -> macrofamiglia -> microfamiglia:
        //   - descrizione via lookup agganciata ad ArtAnagrafica ('art_key' su A -> 'key' sulla tabella);
        //   - oppure descrizione "a 2 salti" agganciata a un altro livello gia' joinato ('via' = slot,
        //     'via_key' = colonna su quella tabella -> 'key' sulla tabella) — es. la macrofamiglia passa
        //     dalla Famiglia (Famiglia.CodMacrofamiglia -> MacrofamiglieArt.CodMacrofamiglia);
        //   - oppure codice diretto da ArtAnagrafica ('art_col'); altrimenti colonna vuota.
        $famAlias = []; // slot con lookup riuscito => alias SQL (per i join "via")
        foreach (self::SLOT_FAMIGLIE as $slot => $etichetta) {
            $f = (array) ($famiglie[$slot] ?? []);
            $tab = trim((string) ($f['tabella'] ?? ''));
            $key = trim((string) ($f['key'] ?? ''));
            $descr = trim((string) ($f['descr'] ?? ''));
            $artKey = trim((string) ($f['art_key'] ?? ''));
            $via = trim((string) ($f['via'] ?? ''));
            $viaKey = trim((string) ($f['via_key'] ?? ''));
            $artCol = trim((string) ($f['art_col'] ?? ''));
            $conDbGruppo = (bool) ($f['db_gruppo'] ?? true);

            if ($tab !== '' && $key !== '' && $descr !== '') {
                $alias = 'F_'.$slot;
                $left = null;   // tabella/alias a cui agganciarsi
                $leftKey = '';  // colonna su quella tabella
                if ($via !== '' && $viaKey !== '' && isset($famAlias[$via])) {
                    $left = $famAlias[$via];
                    $leftKey = $viaKey;
                } elseif ($artKey !== '') {
                    $left = 'A';
                    $leftKey = $artKey;
                }

                if ($left !== null) {
                    $cond = "{$alias}.{$this->ident($key)} = {$left}.{$this->ident($leftKey)}";
                    if ($conDbGruppo) {
                        $cond = "{$alias}.DBGruppo = {$left}.DBGruppo AND ".$cond;
                    }
                    $joins .= "\n            LEFT JOIN {$this->ident($tab)} {$alias} WITH (NOLOCK) ON {$cond}";
                    $select[] = "{$alias}.{$this->ident($descr)} AS {$etichetta}";
                    $famAlias[$slot] = $alias;

                    continue;
                }
            }

            if ($artCol !== '') {
                $select[] = "A.{$this->ident($artCol)} AS {$etichetta}";
            } else {
                $select[] = "'' AS {$etichetta}";
            }
        }

        $selectSql = implode(",\n                ", $select);
        $sql = <<<SQL
            SELECT
                {$selectSql}
            FROM {$tabMm} MM WITH (NOLOCK){$joins}
            SQL;

        // Filtri (bound): CodOpMag IN (...) e, se c'e' la testata documento, operatore creatore.
        $where = [];
        $bind = [];

        $codOpMag = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $codOpMag), static fn (string $v) => $v !== ''));
        if ($codOpMag !== []) {
            $ph = implode(',', array_fill(0, count($codOpMag), '?'));
            $where[] = "MM.{$colCodOpMag} IN ({$ph})";
            foreach ($codOpMag as $v) {
                $bind[] = ctype_digit($v) ? (int) $v : $v;
            }
        }

        $operatore = trim($operatore);
        if ($operatore !== '' && $docAttiva) {
            $where[] = "D.{$this->ident($colOperatore)} = ?";
            $bind[] = $operatore;
        }

        if ($where !== []) {
            $sql .= "\n            WHERE ".implode("\n              AND ", $where);
        }
        // Niente ORDER BY in SQL (colonne non indicizzate): ordino per data in PHP.

        $rows = $this->connection->select($sql, $bind);

        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[] = new CaricoProduzione(
                data: $this->dataIt($r['Data'] ?? null),
                articolo: trim((string) ($r['Articolo'] ?? '')),
                descrizione: trim((string) ($r['Descrizione'] ?? '')),
                lotto: trim((string) ($r['Lotto'] ?? '')),
                quantita: (float) ($r['Quantita'] ?? 0),
                um: trim((string) ($r['Um'] ?? '')),
                magazzino: trim((string) ($r['Magazzino'] ?? '')),
                lottoPf: trim((string) ($r['LottoPf'] ?? '')),
                causale: trim((string) ($r['Causale'] ?? '')),
                codOpMag: trim((string) ($r['CodOpMag'] ?? '')),
                operatore: trim((string) ($r['Operatore'] ?? '')),
                famiglia: trim((string) ($r['Famiglia'] ?? '')),
                macrofamiglia: trim((string) ($r['Macrofamiglia'] ?? '')),
                microfamiglia: trim((string) ($r['Microfamiglia'] ?? '')),
            );
        }

        // Ordinamento cronologico (data documento) fatto in PHP per non appesantire la query.
        usort($out, static fn (CaricoProduzione $a, CaricoProduzione $b) => self::chiaveData($a->data) <=> self::chiaveData($b->data));

        return $out;
    }

    /** Valida un identificatore SQL (tabella/colonna) e lo racchiude tra parentesi quadre. */
    private function ident(string $nome): string
    {
        $nome = trim($nome);
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nome)) {
            throw new InvalidArgumentException("Identificatore SQL non valido in mes.carichi_produzione: '{$nome}'.");
        }

        return "[{$nome}]";
    }

    /**
     * @param  list<string>  $cols
     * @return list<string>  colonne validate e racchiuse tra parentesi quadre
     */
    private function colonne(array $cols): array
    {
        return array_values(array_map(fn ($c) => $this->ident((string) $c), $cols));
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

    /** Chiave ordinabile (timestamp) da una data 'd/m/Y H.i.s'; le date assenti vanno in fondo. */
    private static function chiaveData(?string $data): int
    {
        if ($data === null || $data === '') {
            return PHP_INT_MAX;
        }
        $dt = \DateTime::createFromFormat('d/m/Y H.i.s', $data);

        return $dt !== false ? $dt->getTimestamp() : PHP_INT_MAX;
    }
}
