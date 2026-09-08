<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;
use PDO;

/**
 * Adapter di PRODUZIONE verso il gestionale Omni (Microsoft Access via ODBC, sola lettura), §6-bis.
 * Mappa il lotto ESOLVER (articolo + lotto) al lotto interno Omni leggendo `T_Linkfattlotti`, e
 * garantisce che il lotto proposto abbia GIACENZA REALE > 0 (cosi' l'import in Omni non crea negativi).
 *
 * La giacenza si calcola sommando i movimenti del lotto in `T_MovimentoLotto` (`Valore Movimento`,
 * +carico/-scarico) legati via `IDLinkfattlotti`. Selezione a due livelli:
 *   1) match esatto articolo+lotto ESOLVER -> lotto Omni piu' VECCHIO (FIFO su `Data carico`) con giacenza > 0;
 *   2) se quel lotto e' gia' negativo/esaurito su Omni, si risale per SOLO ARTICOLO al lotto Omni piu'
 *      vecchio con giacenza > 0.
 * Cache in-memory per non ripetere query uguali nello stesso export.
 */
final class AccessLottoOmniAdapter implements LottoOmniSourceInterface
{
    private ?PDO $pdo = null;

    /** @var array<string,?string> */
    private array $cache = [];

    /**
     * @param  array<string,mixed>  $connessione  ['dsn','username','password']
     * @param  array<string,mixed>  $mappa        ['tabella','col_articolo','col_lotto','col_lotto_omni',
     *                                             'col_data','col_pk','tabella_movimenti','col_valore','col_link']
     */
    public function __construct(
        private readonly array $connessione,
        private readonly array $mappa,
    ) {}

    public function lottoOmni(string $articoloEsolver, string $lottoEsolver): ?string
    {
        $art = trim($articoloEsolver);
        $lotto = trim($lottoEsolver);
        if ($art === '' || $lotto === '') {
            return null;
        }

        $chiave = $art.'|'.$lotto;
        if (array_key_exists($chiave, $this->cache)) {
            return $this->cache[$chiave];
        }

        // 1) Lotto Omni piu' vecchio CON GIACENZA per l'esatto articolo+lotto ESOLVER.
        $res = $this->piuVecchioConGiacenza($art, $lotto);

        // 2) Fallback: quel lotto non ha alcun lotto Omni con giacenza (negativo/esaurito) ->
        //    si risale per SOLO ARTICOLO al lotto Omni piu' vecchio con giacenza.
        if ($res === null) {
            $res = $this->piuVecchioConGiacenza($art, null);
        }

        return $this->cache[$chiave] = $res;
    }

    /**
     * Lotto Omni (`Lotto entrata`) piu' vecchio (FIFO su `Data carico`) con giacenza reale > 0.
     * Se $lotto e' null il filtro e' sul solo articolo (fallback). Restituisce null se nessun lotto
     * ha giacenza > 0.
     */
    private function piuVecchioConGiacenza(string $art, ?string $lotto): ?string
    {
        $t = (string) $this->mappa['tabella'];
        $ca = (string) $this->mappa['col_articolo'];
        $cl = (string) $this->mappa['col_lotto'];
        $co = (string) $this->mappa['col_lotto_omni'];
        $cd = (string) $this->mappa['col_data'];
        $pk = (string) $this->mappa['col_pk'];
        $tm = (string) $this->mappa['tabella_movimenti'];
        $cv = (string) $this->mappa['col_valore'];
        $ck = (string) $this->mappa['col_link'];

        $where = "L.[{$ca}] = ?";
        $params = [$art];
        if ($lotto !== null) {
            $where .= " AND L.[{$cl}] = ?";
            $params[] = $lotto;
        }

        // INNER JOIN + HAVING SUM(...) > 0: tiene solo i lotti con giacenza reale positiva.
        // GROUP BY sulla PK del lotto = una riga per lotto fisico; ORDER BY Data carico ASC = FIFO.
        // TOP 1 (con eventuali pari-merito) + fetchColumn = il piu' vecchio con giacenza.
        $sql = "SELECT TOP 1 L.[{$co}] AS lottoOmni "
            ."FROM [{$t}] AS L INNER JOIN [{$tm}] AS M ON M.[{$ck}] = L.[{$pk}] "
            ."WHERE {$where} "
            ."GROUP BY L.[{$pk}], L.[{$co}], L.[{$cd}] "
            ."HAVING SUM(M.[{$cv}]) > 0 "
            ."ORDER BY L.[{$cd}] ASC";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();

        return ($val === false || $val === null) ? null : $this->utf8((string) $val);
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

    /** I dati Access sono in Windows-1252: garantisce UTF-8 in uscita. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
