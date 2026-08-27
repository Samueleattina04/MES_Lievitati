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
    | Magazzino 06: giacenze e proposta lotti FIFO (§3 GiacenzaMag06, §5, §8)
    |--------------------------------------------------------------------------
    |
    | Sorgente giacenze/lotti dal gestionale ESOLVER (connessione sqlsrv_gestionale).
    | Schema confermato: MagProgrArticoli (giacenza per articolo/magazzino) e
    | MagProgrLotto (giacenza per lotto). La mappatura colonne e' qui, NON hardcoded,
    | cosi' da adattarla senza toccare la logica.
    */
    'stock' => [
        // 'sqlsrv' (gestionale reale) | 'fixture' (sviluppo/CI/test)
        'adapter' => env('MES_STOCK_ADAPTER', 'sqlsrv'),

        // Verifica giacenza alla conferma materiali; blocca se insufficiente (§5.1). Disattivabile.
        'verifica_giacenza' => (bool) env('MES_STOCK_VERIFICA', true),

        // Magazzino di riferimento: sempre '06' (stringa, non numerico).
        'magazzino' => env('MES_STOCK_MAGAZZINO', '06'),

        // Mappatura schema ESOLVER (confermata).
        'tabella_articoli' => 'MagProgrArticoli',
        'tabella_lotti' => 'MagProgrLotto',
        'col_codice_articolo' => 'CodArt',
        'col_magazzino' => 'CodMag',
        'col_giacenza_articolo' => 'QtaGiacUmMag',
        'col_lotto' => 'RifLottoAlfab',
        'col_giacenza_lotto' => 'QtaGiacenzaUmMag',

        // Campo SQL usato come ordinamento FIFO di FALLBACK. Verificato sui dati reali: sia
        // RifLottoNum (sempre 0) sia RifLottoData (sentinella 1800-01-01) NON sono usabili.
        'campo_fifo' => env('MES_STOCK_CAMPO_FIFO', 'RifLottoNum'),
        'fifo_direzione' => env('MES_STOCK_FIFO_DIR', 'asc'), // asc = piu' vecchio prima

        // FIFO dal CODICE LOTTO: la data reale e' codificata nel codice lotto secondo lo standard
        // aziendale (<prodotto>-<GIORNO><ANNO><PROGRESSIVO>, es. 7317-11126110 = giorno 111 del 2026,
        // progressivo 110). Se attivo, i lotti sono riordinati per (anno, giorno, progressivo) dal
        // piu' vecchio (vedi App\Stock\LottoFifoParser). Disattivabile se un domani il gestionale
        // esporra' una data di carico affidabile (allora si usa 'campo_fifo').
        'fifo_da_codice_lotto' => (bool) env('MES_STOCK_FIFO_DA_CODICE', true),

        // Fixture giacenze per sviluppo/test (un unico JSON: { "CODART": {giacenza, lotti:[...]} }).
        'fixture_path' => base_path('tests/fixtures/stock'),
    ],

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
    | Semilavorati: lotto in uscita, propagazione e prelievo da stock (§5.3, change #1/#3)
    |--------------------------------------------------------------------------
    |
    | Sorgente per riconoscere un lotto di semilavorato "gia' esistente a sistema" (prelievo da
    | stock -> fase chiusa senza consumo componenti). Dietro un punto di estensione
    | (LottoSemilavoratoSourceInterface) per poter cambiare sorgente senza toccare la logica:
    |   - 'lotti_prodotto' : i lotti gia' registrati in lotti_prodotto (default).
    |   - (futuro)         : giacenza semilavorati su un magazzino dedicato del gestionale.
    | TODO-DECISIONE: confermare la sorgente reale con il committente ([DA CONFERMARE] §5.3).
    */
    'semilavorato' => [
        'sorgente_lotti' => env('MES_SEMILAV_SORGENTE_LOTTI', 'lotti_prodotto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export tracciati per i gestionali (§10)
    |--------------------------------------------------------------------------
    |
    | Costanti del tracciato ESOLVER (versamenti a magazzino), dedotte dal file di esempio reale
    | "lievitati 30-06-2026.csv". Il tracciato e' un CSV separato da ';' con ';' finale, SENZA BOM.
    | Riga 1 = intestazione fissa; poi una riga per lotto prodotto:
    |   causale ; data(gg/mm/aaaa) ; codice articolo ; quantita(virgola) ; lotto ; col6 ; col7 ;
    | I valori 'col6' (es. 01) e 'col7' (es. 850) sono costanti del tracciato (magazzino/causale riga
    | da confermare col committente): qui sono parametrizzati per adattarli senza toccare il codice.
    */
    'export' => [
        'esolver' => [
            'intestazione' => env('MES_ESOLVER_INTESTAZIONE', '10;20;150;180;270;260;140'),
            'causale' => env('MES_ESOLVER_CAUSALE', '103'),
            'col6' => env('MES_ESOLVER_COL6', '01'),
            'col7' => env('MES_ESOLVER_COL7', '850'),
        ],
        // Omni (§6-bis): file .xlsx di tracciabilità per l'import — due fogli (orizzontale + lungo).
        // Dedotto dal file reale "Tracciabilità ...xlsm". 'operatore' riempie la colonna omonima.
        'omni' => [
            'operatore' => env('MES_OMNI_OPERATORE', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracciabilita' lotto (movimenti di magazzino ESOLVER, §6-bis)
    |--------------------------------------------------------------------------
    |
    | Ricostruzione carichi/scarichi di un lotto dai movimenti reali del gestionale
    | (MovimMagLotto + MovimMagazzino). Regole dedotte dai dati reali:
    |   - TipoMovMag = 2  -> CARICO  (versamento "Carico da produzione")
    |   - TipoMovMag = 3  -> SCARICO (consumo   "Consumo per produzione")
    | Il legame componente->prodotto e' RifLottoPFAlfanum (lotto del prodotto finito/semilavorato);
    | ricorrendo si risale l'intera distinta con i lotti realmente usati. 'max_livelli' e' la guardia
    | anti-loop sulla profondita'. 'adapter' segue la stessa sorgente delle giacenze (sqlsrv|fixture).
    */
    'tracciabilita' => [
        'adapter' => env('MES_TRACCIABILITA_ADAPTER', env('MES_STOCK_ADAPTER', 'sqlsrv')),
        'tipo_mov_carico' => (int) env('MES_TRACC_TIPO_CARICO', 2),
        'tipo_mov_scarico' => (int) env('MES_TRACC_TIPO_SCARICO', 3),
        'max_livelli' => (int) env('MES_TRACC_MAX_LIVELLI', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gestionale Omni (Microsoft Access via ODBC, sola lettura) — §6-bis
    |--------------------------------------------------------------------------
    |
    | Mappatura lotto fornitore -> lotto Omni per l'export Omni: dal lotto fornitore (che ha ESOLVER)
    | si cerca in Omni il lotto Omni associato e si prende, in automatico, il piu' VECCHIO con
    | giacenza > 0 (FIFO). Connessione ODBC (DSN Access), credenziali dal .env (ACCESS_DSN/USERNAME/
    | PASSWORD). La mappatura tabella/colonne va compilata dopo aver ispezionato il DB con `omni:schema`.
    */
    'omni' => [
        'adapter' => env('MES_OMNI_ADAPTER', 'fixture'), // 'access' (reale) | 'fixture' (dev/test)
        'connessione' => [
            'dsn' => env('ACCESS_DSN', ''),
            'username' => env('ACCESS_USERNAME', ''),
            'password' => env('ACCESS_PASSWORD', ''),
        ],
        // Mappatura tabella lotti Omni (da compilare: nomi reali dal comando omni:schema).
        'lotti' => [
            'tabella' => env('MES_OMNI_TABELLA', ''),
            'col_articolo' => env('MES_OMNI_COL_ARTICOLO', ''),
            'col_lotto_fornitore' => env('MES_OMNI_COL_LOTTO_FORNITORE', ''),
            'col_lotto_omni' => env('MES_OMNI_COL_LOTTO_OMNI', ''),
            'col_data' => env('MES_OMNI_COL_DATA', ''),          // per il FIFO (piu' vecchio)
            'col_giacenza' => env('MES_OMNI_COL_GIACENZA', ''),  // per il filtro giacenza > 0
        ],
    ],

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
