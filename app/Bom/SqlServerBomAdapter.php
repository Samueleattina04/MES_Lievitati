<?php

declare(strict_types=1);

namespace App\Bom;

use App\Bom\Contracts\BomSourceAdapterInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Adapter di PRODUZIONE verso il gestionale Passepartout/Mexal (SQL Server), SOLA LETTURA (§4).
 *
 * Incapsula la query di esplosione ricorsiva validata sui dati reali (§4.3):
 *  - una CTE ricorsiva esplode i soli componenti che sono a loro volta distinte (semilavorati);
 *  - un secondo passaggio (UNION ALL, fuori dalla ricorsione => NOT EXISTS ammesso) recupera
 *    le materie prime foglia di ciascun nodo semilavorato.
 * Le quantita' sono normalizzate dividendo per QtaRifDb lungo tutto il percorso.
 *
 * REGOLE (§4.2, criterio 10): connessione con permessi db_datareader, WITH (NOLOCK) ovunque,
 * nessuna scrittura. Richiede le estensioni PHP sqlsrv/pdo_sqlsrv (Windows/IIS, §2-bis.1).
 */
final class SqlServerBomAdapter implements BomSourceAdapterInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function explode(string $codiceArticoloRadice): BomExplosion
    {
        $rows = $this->connection->select($this->queryEsplosione(), [$codiceArticoloRadice]);

        $righe = array_map(function ($row): BomRow {
            $r = (array) $row;

            return new BomRow(
                livello: (int) $r['Livello'],
                articolo: (string) $r['Articolo'],
                articoloPadre: $r['ArticoloPadre'] !== null ? (string) $r['ArticoloPadre'] : null,
                descrizione: $r['Descrizione'] !== null ? (string) $r['Descrizione'] : null,
                udm: $r['UdM'] !== null ? (string) $r['UdM'] : null,
                qtaPerUnita: (float) $r['QtaCumulata'],
                isProdotto: (bool) $r['IsProdotto'],
                posizione: (int) ($r['Posizione'] ?? 0),
            );
        }, $rows);

        return new BomExplosion($codiceArticoloRadice, $righe);
    }

    public function esisteArticolo(string $codice): bool
    {
        $row = $this->connection->selectOne(
            'SELECT TOP 1 1 AS Esiste FROM DBaseVersioni WITH (NOLOCK) WHERE CodDb = ?',
            [$codice],
        );

        return $row !== null;
    }

    public function cercaArticoli(string $query, int $limit = 25): array
    {
        $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $query).'%';

        $rows = $this->connection->select(
            <<<'SQL'
            SELECT TOP (?) V.CodDb AS codice, V.DesDb AS descrizione
            FROM DBaseVersioni V WITH (NOLOCK)
            WHERE ISNULL(V.ConfAltDb, '') = ''
              AND (V.CodDb LIKE ? OR V.DesDb LIKE ?)
            GROUP BY V.CodDb, V.DesDb
            ORDER BY V.CodDb
            SQL,
            [$limit, $like, $like],
        );

        return array_map(static fn ($r) => [
            'codice' => (string) $r->codice,
            'descrizione' => $r->descrizione !== null ? (string) $r->descrizione : null,
        ], $rows);
    }

    public function flagLottoPerArticoli(array $codici): array
    {
        $codici = array_values(array_unique(array_filter(
            array_map(static fn ($c) => trim((string) $c), $codici),
            static fn (string $c) => $c !== '',
        )));

        if ($codici === []) {
            return [];
        }

        $out = [];
        // SQL Server ammette ~2100 parametri per query: chunk prudente.
        foreach (array_chunk($codici, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->connection->select(
                "SELECT CodArt, MagGiacPerLotti FROM ArtAnagrafica WITH (NOLOCK) WHERE CodArt IN ({$placeholders})",
                $chunk,
            );
            foreach ($rows as $r) {
                // Verificato sui dati reali: MagGiacPerLotti = 0 -> non a lotti; <> 0 (es. 4) -> a lotti.
                $out[(string) $r->CodArt] = ((int) $r->MagGiacPerLotti) !== 0;
            }
        }

        return $out;
    }

    /**
     * Query di esplosione (§4.3) estesa con il secondo passaggio per le foglie.
     * Un solo binding posizionale: il codice dell'articolo radice.
     */
    private function queryEsplosione(): string
    {
        return <<<'SQL'
        ;WITH VersioniUniche AS (
            SELECT *,
                ROW_NUMBER() OVER (
                    PARTITION BY CodDb
                    ORDER BY
                        CASE WHEN ISNULL(ConfAltDb, '') = '' THEN 0 ELSE 1 END,  -- preferisci config standard
                        DataDecorrenza DESC
                ) AS RigaUnica
            FROM DBaseVersioni WITH (NOLOCK)
        ),
        Esplosione AS (
            SELECT
                0 AS Livello,
                V.CodDb AS Articolo,
                CAST(NULL AS VARCHAR(100)) AS ArticoloPadre,
                CAST(V.DesDb AS VARCHAR(200)) AS Descrizione,
                V.UnitaMisura AS UdM,
                CAST(1.0 AS DECIMAL(18,6)) AS QtaCumulata,
                V.QtaRifDb AS QtaRifDbSelf,
                V.DBGruppo, V.CodDb AS CodDbOriginale, V.VarianteArt, V.Libero1,
                V.TipoConfDb, V.ConfAltDb, V.DataDecorrenza,
                CAST(V.CodDb AS VARCHAR(MAX)) AS PercorsoAntiLoop
            FROM VersioniUniche V
            WHERE V.CodDb = ? AND V.RigaUnica = 1

            UNION ALL

            SELECT
                E.Livello + 1,
                R.CodArtComponente,
                CAST(E.CodDbOriginale AS VARCHAR(100)) AS ArticoloPadre,
                CAST(COALESCE(NULLIF(R.DesEstesa, ''), VComp.DesDb) AS VARCHAR(200)),
                R.UnitaMisuraTecnica,
                CAST(E.QtaCumulata * (R.QtaComponente / NULLIF(E.QtaRifDbSelf, 0)) AS DECIMAL(18,6)),
                VComp.QtaRifDb,
                R.DBGruppo, R.CodArtComponente, R.VarianteArtComp, R.Libero1,
                R.TipoConfDb, R.AltConfDb, R.DataDecorrenza,
                E.PercorsoAntiLoop + '>' + R.CodArtComponente
            FROM DBaseRighe R WITH (NOLOCK)
            INNER JOIN Esplosione E
                ON  R.DBGruppo = E.DBGruppo AND R.CodDb = E.CodDbOriginale
                AND ISNULL(R.VarianteArt,'') = ISNULL(E.VarianteArt,'')
                AND ISNULL(R.Libero1,'') = ISNULL(E.Libero1,'')
                AND R.TipoConfDb = E.TipoConfDb
                AND ISNULL(R.AltConfDb,'') = ISNULL(E.ConfAltDb,'')
                AND R.DataDecorrenza = E.DataDecorrenza
            INNER JOIN VersioniUniche VComp
                ON VComp.CodDb = R.CodArtComponente AND VComp.RigaUnica = 1
            WHERE E.PercorsoAntiLoop NOT LIKE '%' + R.CodArtComponente + '%'
        )
        -- (1) Nodi prodotti (radice + semilavorati) dalla ricorsione
        SELECT
            E.Livello,
            E.Articolo,
            E.ArticoloPadre,
            E.Descrizione,
            E.UdM,
            E.QtaCumulata,
            CAST(1 AS BIT) AS IsProdotto,
            0 AS Posizione
        FROM Esplosione E

        UNION ALL

        -- (2) Materie prime foglia: figli dei nodi prodotti che NON hanno una propria distinta.
        --     Fuori dalla ricorsione => NOT EXISTS ammesso.
        SELECT
            E.Livello + 1 AS Livello,
            R.CodArtComponente AS Articolo,
            CAST(E.Articolo AS VARCHAR(100)) AS ArticoloPadre,
            CAST(NULLIF(R.DesEstesa, '') AS VARCHAR(200)) AS Descrizione,
            R.UnitaMisuraTecnica AS UdM,
            CAST(E.QtaCumulata * (R.QtaComponente / NULLIF(E.QtaRifDbSelf, 0)) AS DECIMAL(18,6)) AS QtaCumulata,
            CAST(0 AS BIT) AS IsProdotto,
            0 AS Posizione
        FROM Esplosione E
        INNER JOIN DBaseRighe R WITH (NOLOCK)
            ON  R.DBGruppo = E.DBGruppo AND R.CodDb = E.CodDbOriginale
            AND ISNULL(R.VarianteArt,'') = ISNULL(E.VarianteArt,'')
            AND ISNULL(R.Libero1,'') = ISNULL(E.Libero1,'')
            AND R.TipoConfDb = E.TipoConfDb
            AND ISNULL(R.AltConfDb,'') = ISNULL(E.ConfAltDb,'')
            AND R.DataDecorrenza = E.DataDecorrenza
        WHERE NOT EXISTS (
            SELECT 1 FROM DBaseVersioni VF WITH (NOLOCK) WHERE VF.CodDb = R.CodArtComponente
        )
        ORDER BY Livello, Articolo
        OPTION (MAXRECURSION 100);
        SQL;
    }
}
