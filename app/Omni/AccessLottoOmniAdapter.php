<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;
use PDO;

/**
 * Adapter di PRODUZIONE verso il gestionale Omni (Microsoft Access via ODBC, sola lettura), §6-bis.
 * Per un articolo+lotto ESOLVER restituisce i lotti interni Omni (`Lotto entrata`) CON GIACENZA reale > 0,
 * ordinati FIFO (piu' vecchi prima) e con la loro giacenza, cosi' il traduttore puo' ripartire la quantita'
 * su piu' lotti senza creare negativi in import. In coda aggiunge i lotti dello stesso articolo (fallback)
 * non gia' inclusi nel match esatto articolo+lotto.
 *
 * La giacenza si calcola sommando i movimenti del lotto in `T_MovimentoLotto` (`Valore Movimento`,
 * +carico/-scarico) legati via `IDLinkfattlotti`, raggruppati per `Lotto entrata`. Cache in-memory per
 * non ripetere query uguali nello stesso export.
 */
final class AccessLottoOmniAdapter implements LottoOmniSourceInterface
{
    private ?PDO $pdo = null;

    /** @var array<string,list<array{lotto:string,giacenza:float,data:string}>> */
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

    public function lottiConGiacenza(string $articoloEsolver, string $lottoEsolver): array
    {
        $art = trim($articoloEsolver);
        $lotto = trim($lottoEsolver);
        if ($art === '') {
            return [];
        }

        $chiave = $art.'|'.$lotto;
        if (array_key_exists($chiave, $this->cache)) {
            return $this->cache[$chiave];
        }

        // 1) lotti del match esatto articolo+lotto (FIFO).
        $cand = $lotto !== '' ? $this->candidati($art, $lotto) : [];

        // 2) in coda i lotti dello stesso articolo non gia' presenti (fallback / copertura quantita').
        $visti = [];
        foreach ($cand as $c) {
            $visti[$c['lotto']] = true;
        }
        foreach ($this->candidati($art, null) as $c) {
            if (! isset($visti[$c['lotto']])) {
                $cand[] = $c;
                $visti[$c['lotto']] = true;
            }
        }

        return $this->cache[$chiave] = $cand;
    }

    /**
     * Lotti Omni (`Lotto entrata`) con giacenza reale > 0, dal piu' vecchio (FIFO su `Data carico`).
     * Se $lotto e' null filtra sul solo articolo.
     *
     * @return list<array{lotto:string,giacenza:float,data:string}>
     */
    private function candidati(string $art, ?string $lotto): array
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

        // INNER JOIN + HAVING SUM(...) > 0: solo lotti con giacenza reale positiva. Una riga per
        // Lotto entrata (= unita' di stock Omni); giacenza = somma movimenti; FIFO su MIN(Data carico).
        $sql = "SELECT L.[{$co}] AS lottoOmni, SUM(M.[{$cv}]) AS giac, MIN(L.[{$cd}]) AS dataCarico "
            ."FROM [{$t}] AS L INNER JOIN [{$tm}] AS M ON M.[{$ck}] = L.[{$pk}] "
            ."WHERE {$where} "
            ."GROUP BY L.[{$co}] "
            ."HAVING SUM(M.[{$cv}]) > 0 "
            ."ORDER BY MIN(L.[{$cd}]) ASC";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lottoOmni = trim($this->utf8((string) ($r['lottoOmni'] ?? '')));
            if ($lottoOmni === '') {
                continue;
            }
            $out[] = [
                'lotto' => $lottoOmni,
                'giacenza' => (float) ($r['giac'] ?? 0),
                'data' => (string) ($r['dataCarico'] ?? ''),
            ];
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

    /** I dati Access sono in Windows-1252: garantisce UTF-8 in uscita. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
