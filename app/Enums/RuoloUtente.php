<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ruoli applicativi (§7). Il valore stringa e' persistito su users.ruolo.
 */
enum RuoloUtente: string
{
    case Operatore = 'operatore';
    case Backoffice = 'backoffice';
    case Pianificazione = 'pianificazione';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Operatore => 'Operatore di reparto',
            self::Backoffice => 'Backoffice di produzione',
            self::Pianificazione => 'Responsabile pianificazione',
            self::Admin => 'Amministratore',
        };
    }

    /** Gli operatori accedono via PIN su tablet; gli altri via email+password. */
    public function usaPin(): bool
    {
        return $this === self::Operatore;
    }

    /*
    |--------------------------------------------------------------------------
    | Matrice dei permessi (§7) — unica fonte di verita' usata da Gate, rotte e UI
    |--------------------------------------------------------------------------
    |
    | Admin        : configurazione (reparti/fasi/articoli/utenti) ESCLUSIVA; super-utente,
    |                puo' anche gestire ordini/esportare/vedere dashboard (vedi cancellazione ordine).
    | Backoffice   : dashboard (sola lettura) + export. NON configurazione, NON gestione ordini.
    | Pianificazione: gestione ordini + dashboard. NON configurazione, NON export.
    | Operatore    : nessuna sezione backoffice (usa l'area /operatore con PIN).
    */

    /** Configurazione admin: reparti, tipi fase, mappatura articoli, utenti (esclusiva Admin). */
    public function puoConfigurare(): bool
    {
        return $this === self::Admin;
    }

    /** Creare/gestire/cancellare ordini di produzione. */
    public function puoGestireOrdini(): bool
    {
        return in_array($this, [self::Admin, self::Pianificazione], true);
    }

    /** Esportare i tracciati (pulsante export). */
    public function puoEsportare(): bool
    {
        return in_array($this, [self::Admin, self::Backoffice], true);
    }

    /** Vedere la dashboard di produzione. */
    public function vedeDashboard(): bool
    {
        return in_array($this, [self::Admin, self::Backoffice, self::Pianificazione], true);
    }

    /**
     * Eseguire l'avanzamento di produzione (avvio/conferma/chiusura fasi, split, completamento
     * da stock) — flusso operatore + chiusura massiva backoffice (§7, §8, change #1).
     * L'Operatore e' vincolato ai reparti assegnati; il Backoffice opera su tutti i reparti.
     */
    public function puoAvanzareProduzione(): bool
    {
        return in_array($this, [self::Operatore, self::Backoffice], true);
    }

    /**
     * L'avanzamento e' limitato ai reparti assegnati? Solo per l'Operatore (§7). Il Backoffice
     * non e' vincolato ai reparti: vede e opera su tutte le fasi/step (change #1).
     */
    public function vincolatoAiReparti(): bool
    {
        return $this === self::Operatore;
    }

    /** Consultare la genealogia lotti (traceability/richiami). */
    public function vedeGenealogia(): bool
    {
        return $this->vedeDashboard();
    }

    /** Rotta "home" dopo il login, in base al ruolo. */
    public function rottaHome(): string
    {
        return match ($this) {
            self::Operatore => 'operatore.coda',
            self::Admin => 'admin.index',
            default => 'dashboard',
        };
    }

    /**
     * Permessi in forma serializzabile per il frontend (Inertia shared props).
     *
     * @return array<string,bool>
     */
    public function permessi(): array
    {
        return [
            'configurare' => $this->puoConfigurare(),
            'gestireOrdini' => $this->puoGestireOrdini(),
            'esportare' => $this->puoEsportare(),
            'vedereDashboard' => $this->vedeDashboard(),
            'vedereGenealogia' => $this->vedeGenealogia(),
            'avanzareProduzione' => $this->puoAvanzareProduzione(),
            'vincolatoAiReparti' => $this->vincolatoAiReparti(),
        ];
    }
}

