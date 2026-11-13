<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\ScarichiOmniSourceInterface;
use InvalidArgumentException;
use PDO;

/**
 * Adapter di PRODUZIONE verso Omni (Microsoft Access via ODBC, sola lettura), §6-bis: dice quanti
 * scarichi sono gia' presenti per un lotto di produzione (prodotto finito), per NON ri-scaricarlo.
 *
 * Catena: `T_Linkfattlotti.[Lotto entrata] = lottoPF AND IDProduzione IS NOT NULL` -> `IDProduzione`
 * -> COUNT righe in `T_ContatoreLEXLP` con quello stesso `IDProduzione`. Colonne parametrizzate in
 * config/mes.php (mes.omni.scarichi). Niente date (in queste tabelle sono inaffidabili): solo ID.
 */
final class AccessScarichiOmniAdapter implements ScarichiOmniSourceInterface
{
    private ?PDO $pdo = null;

    /**
     * @param  array<string,mixed>  $connessione  ['dsn','username','password']
     * @param  array<string,mixed>  $mappa        ['tabella_link','col_lotto_pf','col_produzione',
     *                                             'tabella_contatore','col_contatore_produzione','col_contatore_pk']
     */
    public function __construct(
        private readonly array $connessione,
        private readonly array $mappa,
    ) {}

    public function scarichiPerProduzioni(array $lottiProduzione): array
    {
        $lotti = array_values(array_unique(array_filter(
            array_map(static fn ($l) => trim((string) $l), $lottiProduzione),
            static fn (string $l) => $l !== '',
        )));
        if ($lotti === []) {
            return [];
        }

        $tl = $this->ident((string) ($this->mappa['tabella_link'] ?? 'T_Linkfattlotti'));
        $clp = $this->ident((string) ($this->mappa['col_lotto_pf'] ?? 'Lotto entrata'));
        $cpr = $this->ident((string) ($this->mappa['col_produzione'] ?? 'IDProduzione'));
        $tc = $this->ident((string) ($this->mappa['tabella_contatore'] ?? 'T_ContatoreLEXLP'));
        $ccp = $this->ident((string) ($this->mappa['col_contatore_produzione'] ?? 'IDProduzione'));
        $cck = $this->ident((string) ($this->mappa['col_contatore_pk'] ?? 'IDContatoreLExLP'));

        $out = [];
        // Chunk prudenziale sul numero di parametri IN (gli alberi sono piccoli, ma restiamo sicuri).
        foreach (array_chunk($lotti, 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT L.{$clp} AS pf, COUNT(X.{$cck}) AS n "
                ."FROM {$tl} AS L INNER JOIN {$tc} AS X ON X.{$ccp} = L.{$cpr} "
                ."WHERE L.{$cpr} IS NOT NULL AND L.{$clp} IN ({$ph}) "
                ."GROUP BY L.{$clp}";

            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pf = trim($this->utf8((string) ($r['pf'] ?? '')));
                $n = (int) ($r['n'] ?? 0);
                if ($pf !== '' && $n > 0) {
                    $out[$pf] = $n;
                }
            }
        }

        return $out;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                'odbc:'.trim((string) ($this->connessione['dsn'] ?? '')),
                (string) ($this->connessione['username'] ?? ''),
                (string) ($this->connessione['password'] ?? ''),
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    /** Valida un identificatore Access (ammessi anche gli spazi, es. "Lotto entrata") e lo racchiude in []. */
    private function ident(string $nome): string
    {
        $nome = trim($nome);
        if (! preg_match('/^[A-Za-z0-9_ ]+$/', $nome)) {
            throw new InvalidArgumentException("Identificatore Omni non valido in mes.omni.scarichi: '{$nome}'.");
        }

        return "[{$nome}]";
    }

    /** I dati Access sono in Windows-1252: garantisce UTF-8 in uscita. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
