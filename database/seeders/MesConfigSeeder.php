<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RuoloUtente;
use App\Models\ArticoloConfigurazioneMes;
use App\Models\Reparto;
use App\Models\TipoFase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Configurazione MES di base + dati demo per il caso ASSPAN01 (§3, §7).
 * Idempotente (updateOrCreate): puo' essere rilanciato senza duplicare.
 *
 *   php artisan db:seed --class=Database\\Seeders\\MesConfigSeeder
 */
class MesConfigSeeder extends Seeder
{
    public function run(): void
    {
        $reparti = $this->reparti();
        $tipiFase = $this->tipiFase($reparti);
        $this->configurazioneArticoli($tipiFase);
        $this->utenti($reparti);
    }

    /** @return array<string,Reparto> */
    private function reparti(): array
    {
        $definizioni = [
            'IMP' => 'Impasto',
            'LIEV' => 'Lievitazione',
            'FORNO' => 'Forno',
            'CONF' => 'Confezionamento',
        ];

        $reparti = [];
        foreach ($definizioni as $codice => $descrizione) {
            $reparti[$codice] = Reparto::updateOrCreate(
                ['codice' => $codice],
                ['descrizione' => $descrizione, 'attivo' => true],
            );
        }

        return $reparti;
    }

    /**
     * @param array<string,Reparto> $reparti
     * @return array<string,TipoFase>
     */
    private function tipiFase(array $reparti): array
    {
        // codice => [descrizione, [ [repartoCodice, ordine, consuma_materiali, descrizione], ... ] ]
        $definizioni = [
            'IMPASTO' => ['Impasto (singolo reparto)', [
                ['IMP', 1, true, 'Impasto'],
            ]],
            // Fase multi-reparto (criterio 4): lievitazione poi forno.
            'SEMILAV_PANETTONE' => ['Semilavorato panettone (lievitazione + forno)', [
                ['LIEV', 1, true, 'Lievitazione'],
                ['FORNO', 2, false, 'Cottura in forno'],
            ]],
            'CONFEZIONAMENTO' => ['Confezionamento', [
                ['CONF', 1, true, 'Confezionamento'],
            ]],
        ];

        $tipi = [];
        foreach ($definizioni as $codice => [$descrizione, $steps]) {
            $tipo = TipoFase::updateOrCreate(['codice' => $codice], ['descrizione' => $descrizione]);
            $tipo->steps()->delete();
            foreach ($steps as [$repCodice, $ordine, $consuma, $descStep]) {
                $tipo->steps()->create([
                    'reparto_id' => $reparti[$repCodice]->id,
                    'ordine' => $ordine,
                    'consuma_materiali' => $consuma,
                    'descrizione' => $descStep,
                ]);
            }
            $tipi[$codice] = $tipo;
        }

        return $tipi;
    }

    /**
     * Mappa articolo prodotto -> tipo fase (reparto/i). Il reparto e' attributo dell'articolo,
     * stabile fra prodotti diversi e indipendente dalla profondita' in distinta (§3).
     *
     * @param array<string,TipoFase> $tipiFase
     */
    private function configurazioneArticoli(array $tipiFase): void
    {
        $mappaTipoFase = [
            'IMPASTOCOLOMBE/PANETTONI' => 'IMPASTO',
            'IMPASTOTRADPIST/AN/ALB' => 'IMPASTO',
            'IMPASTOTRADPIST/PESC/CIOC' => 'IMPASTO',
            'PANPIST/ANANAS/ALB750' => 'SEMILAV_PANETTONE',
            'PANPIST/PESCA/CIOC750' => 'SEMILAV_PANETTONE',
            'PAN0104' => 'CONFEZIONAMENTO',
            'PAN0136' => 'CONFEZIONAMENTO',
            'ASSPAN01' => 'CONFEZIONAMENTO',
        ];

        foreach ($mappaTipoFase as $codice => $tipoFaseCodice) {
            ArticoloConfigurazioneMes::updateOrCreate(
                ['articolo_codice' => $codice],
                [
                    'tipo_fase_id' => $tipiFase[$tipoFaseCodice]->id,
                    // I nodi prodotti (semilavorati/finiti) hanno un lotto in uscita per la genealogia (§6).
                    'flag_lotto_override' => true,
                ],
            );
        }

        // Materie prime alimentari che richiedono il lotto (§6). Il packaging non lo richiede.
        $conLotto = [
            'ZUCCHERO-SEM', 'BURRO-P', 'TUORLO', 'PT0LI25', 'MIELE', 'SALE', 'MADRE492',
            'SCIROPPO-GLU', 'LATTE-POLV', 'AROMA-PAN', 'ENZIMI', 'LIEVITO', 'EMULSIONANTE',
            'VANIGLIA-BACC', 'SCORZA-ARANCIA', 'UVETTA', 'ANANAS-CAND', 'ALBICOCCA-CUB',
            'PESCA-CAND', 'CIOCC-GOCCE', 'PISTACCHIO', 'GLASSA', 'ZUCCHERO-GRAN', 'ALBUME',
            'OLIO-GIRA', 'AROMA-VAN', 'VINO-ZIB', 'ALCOOL',
        ];
        foreach ($conLotto as $codice) {
            ArticoloConfigurazioneMes::updateOrCreate(
                ['articolo_codice' => $codice],
                ['flag_lotto_override' => true],
            );
        }
    }

    /**
     * @param array<string,Reparto> $reparti
     */
    private function utenti(array $reparti): void
    {
        // Utenti email+password (backoffice/pianificazione/admin).
        $staff = [
            ['admin@lievitati.local', 'Amministratore', RuoloUtente::Admin],
            ['pianificazione@lievitati.local', 'Responsabile Pianificazione', RuoloUtente::Pianificazione],
            ['backoffice@lievitati.local', 'Backoffice Produzione', RuoloUtente::Backoffice],
        ];
        foreach ($staff as [$email, $nome, $ruolo]) {
            User::updateOrCreate(
                ['email' => $email],
                ['name' => $nome, 'ruolo' => $ruolo, 'password' => Hash::make('password'), 'attivo' => true],
            );
        }

        // Operatori con PIN (login rapido su tablet, §7). PIN memorizzato solo hashato.
        $operatori = [
            ['Mario Rossi (Impasto)', '1234', ['IMP']],
            ['Luigi Bianchi (Forno)', '2345', ['LIEV', 'FORNO']],
            ['Anna Verdi (Confezionamento)', '3456', ['CONF']],
            ['Sara Neri (Jolly)', '4567', ['IMP', 'LIEV', 'FORNO', 'CONF']],
        ];
        foreach ($operatori as [$nome, $pin, $repartiCodici]) {
            $utente = User::updateOrCreate(
                ['name' => $nome],
                ['ruolo' => RuoloUtente::Operatore, 'pin_hash' => Hash::make($pin), 'attivo' => true],
            );
            $ids = array_map(fn (string $c) => $reparti[$c]->id, $repartiCodici);
            $utente->reparti()->sync($ids);
        }
    }
}
