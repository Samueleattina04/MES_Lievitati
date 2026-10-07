<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;

/**
 * Confronta l'anagrafica articoli dei due gestionali (§ Check): ESOLVER (SQL Server) e Omni (Access).
 * Produce due elenchi: gli articoli presenti su ESOLVER ma non su Omni, e viceversa. Il confronto e'
 * sulla chiave normalizzata (trim + maiuscolo) per evitare falsi disallineamenti da spazi/maiuscole,
 * ma gli elenchi riportano il codice originale. Logica pura: le sorgenti sono dietro un'interfaccia.
 */
final class CheckService
{
    public function __construct(
        private readonly ArticoliSourceInterface $esolver,
        private readonly ArticoliSourceInterface $omni,
    ) {}

    /**
     * @return array{soloEsolver:list<array{codice:string,descrizione:string}>, soloOmni:list<array{codice:string,descrizione:string}>}
     */
    public function confronta(): array
    {
        $esolver = $this->esolver->articoli();
        $omni = $this->omni->articoli();

        $chiaviEsolver = $this->chiavi($esolver);
        $chiaviOmni = $this->chiavi($omni);

        return [
            'soloEsolver' => $this->differenza($esolver, $chiaviOmni),
            'soloOmni' => $this->differenza($omni, $chiaviEsolver),
        ];
    }

    /**
     * Articoli di $elenco la cui chiave normalizzata non e' presente in $chiaviAltro, ordinati per codice.
     *
     * @param  array<string,string>  $elenco       codice => descrizione
     * @param  array<string,true>  $chiaviAltro  insieme delle chiavi normalizzate dell'altro gestionale
     * @return list<array{codice:string,descrizione:string}>
     */
    private function differenza(array $elenco, array $chiaviAltro): array
    {
        $out = [];
        foreach ($elenco as $codice => $descrizione) {
            if (! isset($chiaviAltro[$this->norm((string) $codice)])) {
                $out[] = ['codice' => (string) $codice, 'descrizione' => (string) $descrizione];
            }
        }

        usort($out, static fn (array $a, array $b) => strcmp($a['codice'], $b['codice']));

        return $out;
    }

    /**
     * @param  array<string,string>  $elenco
     * @return array<string,true>
     */
    private function chiavi(array $elenco): array
    {
        $set = [];
        foreach (array_keys($elenco) as $codice) {
            $set[$this->norm((string) $codice)] = true;
        }

        return $set;
    }

    private function norm(string $codice): string
    {
        return mb_strtoupper(trim($codice));
    }
}
