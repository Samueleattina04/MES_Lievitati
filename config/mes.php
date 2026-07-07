<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sorgente distinte base (BOM)
    |--------------------------------------------------------------------------
    |
    | Determina quale implementazione di BomSourceAdapterInterface viene usata
    | (vedi App\Providers\MesServiceProvider):
    |   - 'sqlsrv'  : SqlServerBomAdapter, interroga il gestionale reale (§4.3).
    |   - 'fixture' : FixtureBomAdapter, legge alberi da file JSON in tests/fixtures/bom.
    |                 Utile in sviluppo/CI dove le estensioni sqlsrv/pdo_sqlsrv o il
    |                 gestionale non sono disponibili.
    */
    'bom_adapter' => env('MES_BOM_ADAPTER', 'sqlsrv'),

    /*
    | Cartella dei fixture usati dal FixtureBomAdapter (percorso assoluto).
    */
    'fixture_path' => base_path('tests/fixtures/bom'),

    /*
    |--------------------------------------------------------------------------
    | Tolleranze di validazione
    |--------------------------------------------------------------------------
    |
    | Tolleranza assoluta (in unita' di misura del componente) usata per validare
    | che la somma delle righe multi-lotto quadri con la quantita' confermata (§6),
    | e che la somma degli split quadri con la quantita' reale prodotta (§5-bis).
    | Default proposto: +/- 0,01 unita'. TODO-DECISIONE: valutare se serve una
    | tolleranza percentuale invece che assoluta ([DA CONFERMARE] §6).
    */
    'tolleranza_multilotto' => (float) env('MES_TOLLERANZA_MULTILOTTO', 0.01),
    'tolleranza_split' => (float) env('MES_TOLLERANZA_MULTILOTTO', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Soglia in ore oltre la quale una fase avviata e non ancora chiusa viene
    | segnalata come "ferma / in ritardo" (individuazione colli di bottiglia, §9).
    */
    'fase_ferma_ore' => (int) env('MES_FASE_FERMA_ORE', 8),

    /*
    |--------------------------------------------------------------------------
    | Autenticazione operatori (PIN)
    |--------------------------------------------------------------------------
    |
    | Login rapido operatore su tablet condiviso tramite PIN numerico (§7).
    */
    'pin' => [
        'min_length' => 4,
        'max_length' => 6,
        // Rate limiting sul login PIN per evitare tentativi ripetuti (§11).
        'max_tentativi' => 5,
        'decay_secondi' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope dei nodi condivisi (split)
    |--------------------------------------------------------------------------
    |
    | 'ordine'      : un nodo condiviso e' prodotto e ripartito solo all'interno
    |                 dello stesso ordine (default v1, §5-bis punto 4).
    | 'multi_ordine': (futuro) riutilizzabile tra ordini diversi.
    | TODO-DECISIONE: estendere a 'multi_ordine' se il committente lo richiede.
    */
    'split_scope' => env('MES_SPLIT_SCOPE', 'ordine'),

    /*
    |--------------------------------------------------------------------------
    | Precisione numerica
    |--------------------------------------------------------------------------
    |
    | Coerente con i dati sorgente del gestionale: DECIMAL(18,6) (§13).
    */
    'decimali_quantita' => 6,

];
