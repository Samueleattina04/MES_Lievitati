<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;
use PDO;

/**
 * Adapter di PRODUZIONE verso il gestionale Omni (Microsoft Access via ODBC, sola lettura), §6-bis.
 * Mappa il lotto ESOLVER (articolo + lotto) al lotto interno Omni leggendo `T_Linkfattlotti`:
 * match esatto su `CodArtEsolver` + `CodLottoEsolver`, filtro giacenza = lotto non chiuso, ordine
 * FIFO su `Data carico`, restituisce `Lotto entrata`. Cache in-memory per non ripetere query uguali.
 */
final class AccessLottoOmniAdapter implements LottoOmniSourceInterface
{
    private ?PDO $pdo = null;

    /** @var array<string,?string> */
    private array $cache = [];

    /**
     * @param  array<string,mixed>  $connessione  ['dsn','username','password']
     * @param  array<string,mixed>  $mappa        ['tabella','col_articolo','col_lotto','col_lotto_omni','col_data','col_lotto_chiuso']
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

        $t = (string) $this->mappa['tabella'];
        $ca = (string) $this->mappa['col_articolo'];
        $cl = (string) $this->mappa['col_lotto'];
        $co = (string) $this->mappa['col_lotto_omni'];
        $cd = (string) $this->mappa['col_data'];
        $cc = (string) $this->mappa['col_lotto_chiuso'];

        $sql = "SELECT TOP 1 [{$co}] AS lottoOmni FROM [{$t}] "
            ."WHERE [{$ca}] = ? AND [{$cl}] = ? AND ([{$cc}] = 0 OR [{$cc}] IS NULL) "
            ."ORDER BY [{$cd}] ASC";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$art, $lotto]);
        $val = $stmt->fetchColumn();

        $res = ($val === false || $val === null) ? null : $this->utf8((string) $val);

        return $this->cache[$chiave] = $res;
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
