<?php

declare(strict_types=1);

namespace App\Bom;

use App\Bom\Contracts\BomSourceAdapterInterface;
use RuntimeException;

/**
 * Implementazione dell'adapter basata su fixture JSON (sviluppo/test/CI).
 *
 * Legge alberi gia' esplosi da file `{codice}.json` nella cartella config('mes.fixture_path').
 * Permette di sviluppare e testare TUTTA la logica di dominio (esplosione -> fasi -> split ->
 * lotti -> export) senza il gestionale reale ne' le estensioni sqlsrv/pdo_sqlsrv.
 *
 * NOTA: le quantita' nei fixture sono rappresentative/coerenti tra loro, non i valori reali
 * del gestionale (non disponibili in questo ambiente). Servono a validare la CORRETTEZZA della
 * struttura e dei calcoli di normalizzazione; i numeri reali vanno verificati con SqlServerBomAdapter.
 */
final class FixtureBomAdapter implements BomSourceAdapterInterface
{
    public function __construct(
        private readonly string $fixturePath,
    ) {}

    public function explode(string $codiceArticoloRadice): BomExplosion
    {
        $data = $this->leggiFixture($codiceArticoloRadice);

        if ($data === null) {
            throw new RuntimeException("Fixture di distinta non trovata per l'articolo '{$codiceArticoloRadice}'.");
        }

        $righe = array_map(
            static fn (array $r) => BomRow::fromArray($r),
            $data['righe'] ?? [],
        );

        return new BomExplosion(
            articoloRadice: $data['articolo_radice'] ?? $codiceArticoloRadice,
            righe: $righe,
        );
    }

    public function esisteArticolo(string $codice): bool
    {
        return $this->leggiFixture($codice) !== null;
    }

    public function cercaArticoli(string $query, int $limit = 25): array
    {
        if (! is_dir($this->fixturePath)) {
            return [];
        }

        $query = mb_strtolower(trim($query));
        $risultati = [];

        foreach (glob(rtrim($this->fixturePath, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $data = $this->decode((string) file_get_contents($file));
            if ($data === null) {
                continue;
            }
            $codice = (string) ($data['articolo_radice'] ?? pathinfo($file, PATHINFO_FILENAME));
            $descrizione = $this->descrizioneRadice($data);

            $haystack = mb_strtolower($codice.' '.(string) $descrizione);
            if ($query === '' || str_contains($haystack, $query)) {
                $risultati[] = ['codice' => $codice, 'descrizione' => $descrizione];
            }

            if (count($risultati) >= $limit) {
                break;
            }
        }

        return $risultati;
    }

    public function flagLottoPerArticoli(array $codici): array
    {
        // In sviluppo/test non c'e' l'anagrafica del gestionale: il flag a lotti proviene dalla
        // configurazione MES (flag_lotto_override). Nessuna informazione da restituire qui.
        return [];
    }

    /** @return array<string,mixed>|null */
    private function leggiFixture(string $codice): ?array
    {
        $file = $this->percorsoFixture($codice);

        if ($file === null || ! is_file($file)) {
            return null;
        }

        return $this->decode((string) file_get_contents($file));
    }

    private function percorsoFixture(string $codice): ?string
    {
        // I codici radice dei fixture sono nomi file sicuri (es. ASSPAN01). Proteggiamo comunque
        // da path traversal rifiutando separatori di percorso.
        if ($codice === '' || str_contains($codice, '/') || str_contains($codice, '\\') || str_contains($codice, '..')) {
            return null;
        }

        return rtrim($this->fixturePath, '/\\').DIRECTORY_SEPARATOR.$codice.'.json';
    }

    /** @return array<string,mixed>|null */
    private function decode(string $json): ?array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function descrizioneRadice(array $data): ?string
    {
        foreach ($data['righe'] ?? [] as $riga) {
            if (($riga['articolo_padre'] ?? null) === null) {
                return $riga['descrizione'] ?? null;
            }
        }

        return null;
    }
}
