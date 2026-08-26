# RECAP — Stato di fatto del codice

Documento puramente descrittivo, basato sul codice presente nel repository. Le affermazioni citano
il file/classe/metodo di riferimento. Dove il comportamento non è ricostruibile con certezza dal
codice, è indicato esplicitamente. Non contiene valutazioni di qualità o completezza.

> Nota terminologica: numerosi commenti nel codice contengono riferimenti a sezioni tipo `§5`, `§8`,
> ecc. Il documento a cui si riferiscono non è presente nel repository; qui vengono ignorati e si
> descrive solo il codice.

---

## 1. Panoramica

- **Linguaggi**: PHP (backend) e JavaScript/Vue (frontend); SQL nelle query verso il gestionale.
- **Framework backend**: Laravel `^13.8` (`composer.json`), PHP `^8.3`.
- **Frontend**: Inertia.js + Vue 3 con Vite. Dipendenze `require` in `composer.json`:
  `inertiajs/inertia-laravel ^2.0`, `laravel/sanctum ^4.0`, `tightenco/ziggy ^2.0`, `laravel/tinker ^3.0`.
  `require-dev` include `laravel/breeze ^2.4`, `laravel/pint`, `phpunit/phpunit ^12.5`, `laravel/pail`,
  `laravel/pao`, `mockery`, `nunomaduro/collision`, `fakerphp/faker`.
- **Dipendenze JS** (`package.json`, `devDependencies`): `@inertiajs/vue3`, `vue`, `vite ^8`,
  `laravel-vite-plugin`, `@vitejs/plugin-vue`, `axios`, `@tailwindcss/forms`, `tailwindcss ^3.2.1`,
  `@tailwindcss/vite ^4.0.0`, `autoprefixer`, `postcss`, `concurrently`.
- **Database**: due connessioni in `config/database.php`: `mysql` (default) e `sqlsrv_gestionale`
  (driver `sqlsrv`, sola lettura verso il gestionale). In `.env` `DB_CONNECTION=mysql`.
- **Avvio (script in `composer.json`)**:
  - `composer setup`: `composer install`, copia `.env`, `key:generate`, `migrate --force`,
    `npm install`, `npm run build`.
  - `composer dev`: avvia in parallelo `php artisan serve`, `queue:listen`, `pail`, `npm run dev`.
  - `composer test`: `config:clear` + `php artisan test`.
  - Build frontend: `npm run build` / `npm run dev` (`package.json` scripts).
- In `.env` presente nel repo: `APP_URL=http://localhost:8000`, `MES_BOM_ADAPTER=fixture`,
  `MES_STOCK_ADAPTER=fixture`. In `.env.example`: `APP_URL=https://...`, `MES_BOM_ADAPTER=sqlsrv`,
  `MES_STOCK_ADAPTER=sqlsrv`, `SESSION_SECURE_COOKIE=true`.
- **Documentazione presente**: `README.md` (setup, comandi, procedura di deploy su IIS),
  `PROMPT_BUILD_CLAUDE_CODE.md` (documento di input, non codice).

---

## 2. Struttura del repository

- `app/`
  - `Bom/` — adapter e DTO per l'esplosione delle distinte base.
  - `Ordini/` — pianificazione e materializzazione ordini (`Planning/` contiene i DTO).
  - `Produzione/` — logica di avanzamento fasi, split, genealogia, regole pure, tolleranze.
  - `Stock/` — adapter giacenze/lotti e allocazione FIFO (`Contracts/` per l'interfaccia).
  - `Export/` — motore di export a template (`Templates/`, `Contracts/`).
  - `Enums/` — enum PHP (ruoli, stati, tipi).
  - `Models/` — modelli Eloquent.
  - `Http/Controllers/` — controller (con sottocartelle `Admin/`, `Operatore/`, `Auth/`).
  - `Http/Controllers/` — sottocartelle `Admin/`, `Operatore/`, `Produzione/`, `Auth/`.
  - `Http/Middleware/`, `Http/Requests/`, `Rules/`, `Support/`, `Console/Commands/`, `Providers/`.
- `database/migrations/` — 3 migrazioni di skeleton Laravel + 7 migrazioni `2026_07_06_0000xx_*`
  + `2026_07_14_000001_add_completata_da_stock_to_fasi_ordine.php`.
- `database/seeders/` — `DatabaseSeeder`, `MesConfigSeeder`. `database/factories/UserFactory.php`.
- `resources/js/` — `app.js`, `bootstrap.js`, `Pages/`, `Layouts/`, `Components/`, `offline/`.
- `resources/views/app.blade.php` — vista root Inertia.
- `routes/` — `web.php`, `auth.php`, `console.php`.
- `config/` — configurazione Laravel + `config/mes.php` (parametri applicativi).
- `public/` — `web.config`, `manifest.webmanifest`, `sw.js`, `icons/icon.svg`, `build/` (asset compilati).
- `tests/` — `Unit/`, `Feature/`, `fixtures/` (`bom/`, `stock/`).

---

## 3. Modello dati

Fonte: migrazioni in `database/migrations/` e modelli in `app/Models/`. Tutte le quantità sono
`decimal(18,6)`. Ogni migrazione MES definisce foreign key con `cascadeOnDelete`, `nullOnDelete` o
`restrictOnDelete` come indicato.

### Tabelle skeleton Laravel
`users`, `password_reset_tokens`, `sessions` (`0001_01_01_000000`), `cache`/`cache_locks`
(`..._000001`), `jobs`/`job_batches`/`failed_jobs` (`..._000002`).

### `users` (esteso — `2026_07_06_000002_add_mes_fields_to_users.php`)
Aggiunge: `ruolo` string(30) default `'operatore'`, `pin_hash` nullable, `attivo` bool default true.
Rende `email` e `password` `nullable`. Modello `App\Models\User`: cast `ruolo` → `RuoloUtente`,
`attivo` → bool; relazione `reparti()` (belongsToMany via `operatore_reparto`); metodi
`haRuolo()`, `eOperatore()`, `eAdmin()`, `puoAvanzareProduzione()`, `vincolatoAiReparti()`.

### `operatore_reparto` (pivot, stessa migrazione)
`user_id` + `reparto_id` (cascade), unique `(user_id, reparto_id)`.

### `reparti` (`..._000001`)
`codice` unique, `descrizione`, `attivo`. Modello `Reparto`: relazioni `operatori()`, `stepTipoFase()`.

### `tipi_fase` + `tipo_fase_step` (`..._000001`)
`tipi_fase`: `codice` unique, `descrizione`. `tipo_fase_step`: `tipo_fase_id` (cascade),
`reparto_id` (restrict), `ordine`, `descrizione`, `consuma_materiali` bool, unique `(tipo_fase_id, ordine)`.
Modelli `TipoFase` (`steps()` ordinati per `ordine`) e `TipoFaseStep` (`tipoFase()`, `reparto()`).

### `articoli` + `articolo_configurazione_mes` (`..._000003`)
`articoli`: `codice` unique, `descrizione`, `udm`, `udm_tecnica`, `tipo` string default `'acquistato'`,
`flag_lotto` bool. Modello `Articolo`: cast `tipo` → `TipoArticolo`; `configurazioneMes()` (hasOne su
`articolo_codice`); metodo `richiedeLotto()` che ritorna `flag_lotto_override` se presente, altrimenti
`flag_lotto`.
`articolo_configurazione_mes`: `articolo_codice` unique, `reparto_default_id` (nullOnDelete),
`tipo_fase_id` (nullOnDelete), `flag_lotto_override` bool nullable, `note`. Modello
`ArticoloConfigurazioneMes` con `repartoDefault()`, `tipoFase()`, `articolo()`.

### `ordini_produzione` + `distinta_righe` (`..._000004`)
`ordini_produzione`: `numero` unique, `articolo_finito_codice`, `descrizione_articolo`, `quantita`,
`udm`, `data` date, `stato` string default `'aperto'`, `origine` string default `'manuale'`,
`creato_da_id` (nullOnDelete su `users`), `note`, `esploso_at`, `esportato_at`. Modello
`OrdineProduzione`: cast `stato` → `StatoOrdine`, `origine` → `OrigineOrdine`, `data`/timestamp date;
relazioni `creatoDa()`, `distintaRighe()`, `fasi()`.
`distinta_righe`: `ordine_id` (cascade), `articolo_padre_codice` nullable, `articolo_figlio_codice`,
`descrizione`, `quantita`, `qta_per_unita`, `udm`, `livello_relativo`, `posizione`, `e_nodo_prodotto` bool.

### `fasi_ordine`, `fase_precedenze`, `fase_ordine_step`, `materiali_fase` (`..._000005`)
`fasi_ordine`: `ordine_id` (cascade), `articolo_prodotto_codice`, `descrizione`,
`quantita_pianificata`, `quantita_prodotta` nullable, `udm`, `livello_relativo`, `stato` default
`'da_lavorare'`, `tipo_fase_id`/`reparto_step_corrente_id`/`operatore_id` (nullOnDelete),
`timestamp_inizio`/`timestamp_fine`, `is_nodo_condiviso` bool, `split_completato` bool,
`completata_da_stock` bool (default false — aggiunta da `2026_07_14_000001`: fase soddisfatta
prelevando un lotto di semilavorato esistente, senza consumo componenti); unique
`(ordine_id, articolo_prodotto_codice)`. Modello `FaseOrdine`: relazioni `ordine()`, `operatore()`,
`tipoFase()`, `repartoCorrente()`, `materiali()`, `steps()`, `lottiProdotto()`, e self-referential
`fasiFiglie()`/`fasiPadre()` (belongsToMany su `fase_precedenze`), `splitInUscita()`/`splitInEntrata()`
(hasMany su `fase_splits`); metodo `eChiusa()`.
`fase_precedenze`: `fase_id` + `fase_figlia_id` (entrambe cascade), unique `(fase_id, fase_figlia_id)`.
`fase_ordine_step`: `fase_ordine_id` (cascade), `reparto_id` (restrict), `ordine`, `descrizione`,
`consuma_materiali`, `stato`, `operatore_id` (nullOnDelete), timestamp inizio/fine, unique
`(fase_ordine_id, ordine)`. Modello `FaseOrdineStep`.
`materiali_fase`: `fase_ordine_id` (cascade), `articolo_codice`, `descrizione`, `quantita_pianificata`,
`udm`, `flag_lotto`, `e_semilavorato`, `fase_produttrice_id` (nullOnDelete), `posizione`. Modello
`MaterialeFase`: `fase()`, `faseProduttrice()`, `consumo()` (hasOne). Nota: dalla change #2 il
`flag_lotto` è impostato in base a `Articolo::richiedeLotto()` anche per i componenti semilavorato
(prima erano esclusi): la riga-componente semilavorato porta il lotto propagato dalla fase produttrice.

### `consumi_materiale`, `consumo_materiale_lotti`, `lotti_prodotto`, `fase_splits` (`..._000006`)
`consumi_materiale`: `materiale_fase_id` unique (cascade), `quantita_effettiva`, `confermato_da_id`
(nullOnDelete), `confermato_at`, `client_uuid`. Modello `ConsumoMateriale`: `materiale()`,
`confermatoDa()`, `lotti()`.
`consumo_materiale_lotti`: `consumo_materiale_id` (cascade), `lotto`, `quantita`. Modello
`ConsumoMaterialeLotto`.
`lotti_prodotto`: `fase_ordine_id` (cascade), `articolo_codice`, `lotto`, `quantita` nullable,
`creato_da_id` (nullOnDelete), `client_uuid`. Modello `LottoProdotto`.
`fase_splits`: `fase_sorgente_id` + `fase_destinazione_id` (entrambe cascade), `quantita_assegnata`,
`operatore_id` (nullOnDelete), `client_uuid`, unique `(fase_sorgente_id, fase_destinazione_id)`.
Modello `FaseSplit`: `faseSorgente()`, `faseDestinazione()`, `operatore()`.

### `log_eventi`, `sync_queue` (`..._000007`)
`log_eventi`: `user_id` (nullOnDelete), `tipo_evento`, `nullableMorphs('soggetto')`, `descrizione`,
`dati` json, `created_at` (senza `updated_at`). Modello `LogEvento`: `public $timestamps = false`,
cast `dati` → array; relazioni `user()`, `soggetto()` (morphTo).
`sync_queue`: `client_uuid` unique, `tipo_azione`, `payload` json, `processato` bool, `processato_at`,
`errore` text. Modello `SyncQueue`: cast `payload` → array.

---

## 4. Moduli e responsabilità

- **`app/Bom`** — Esplosione distinte.
  - `Contracts\BomSourceAdapterInterface`: `explode()`, `esisteArticolo()`, `cercaArticoli()`.
  - `SqlServerBomAdapter`: implementa l'interfaccia con query su `DBaseVersioni`/`DBaseRighe`
    (CTE ricorsiva in `queryEsplosione()`), usando la connessione `sqlsrv_gestionale`. **Scelta della
    versione di distinta** (quando un articolo ha piu' versioni): si esplode la **distinta preferenziale**
    del gestionale (`DBaseVersioni.OldPreferenziale <> 0`); in mancanza, fallback alla configurazione
    standard (`ConfAltDb` vuoto) e poi alla `DataDecorrenza` piu' recente. La preferenziale puo' essere
    una configurazione alternativa (`ConfAltDb` valorizzato), quindi ha priorita' sulla standard.
  - `FixtureBomAdapter`: legge alberi da file JSON in `tests/fixtures/bom`.
  - `BomRow` (readonly DTO), `BomExplosion` (collezione con helper `codiciNodiProdotti()`,
    `figliDiretti()`, `occorrenze()`, `padriDistinti()`, `eCondiviso()`, `livelloMassimo()`).
- **`app/Ordini`** — Creazione ordine.
  - `OrderExplosionPlanner::plan()` (senza DB) trasforma una `BomExplosion` + quantità in `PhasePlan`
    (`Planning\PhasePlan`, `PlannedPhase`, `PlannedMaterial`).
  - `OrderMaterializer::materializza()` scrive su DB (articoli cache, `distinta_righe`, `fasi_ordine`,
    `materiali_fase`, `fase_ordine_step`, `fase_precedenze`) in transazione.
  - `OrdineProduzioneService::creaManuale()` orchestra validazione → esplosione → piano →
    creazione ordine → materializzazione → log; `generaNumero()` genera `OP-YYYYMMDD-NNN`.
- **`app/Produzione`** — Avanzamento produzione.
  - `FaseGate` (statico, senza DB): `precedenzeSoddisfatte()`, `stepAvviabile()`, `motivoBlocco()`,
    `tuttiStepChiusi()`.
  - `FaseWorkflowService`: `avvia()`, `completaDaStock()`, `confermaMateriale()`, `chiudiStep()` (con
    `DB::transaction` e `lockForUpdate`), più `contesto()`, `stepAvviabile()`, `motivoBlocco()`,
    `controllaGiacenza()`, `chiudiFaseDiretta()`, `verificaCompletamentoOrdine()`. Costruttore
    con `?LottoSemilavoratoSourceInterface` per riconoscere i lotti esistenti (change #3).
  - `ChiusuraMassivaService::chiudiOrdine()` — chiusura in blocco di tutte le fasi di un ordine
    (change #4): ordina le fasi bottom-up (topologico su `fasiFiglie`), per ciascuna produce
    (avvia → conferma materiali → chiudi con lotto, + split automatico sui nodi condivisi) oppure
    `completaDaStock()`; tutto in un'unica `DB::transaction` (rollback totale su errore). Riusa
    `FaseWorkflowService` e `SplitService`.
  - `SplitService`: `destinazioni()`, `quantitaDaRipartire()`, `registra()`.
  - `GenealogiaService`: `aRitroso()`, `inAvanti()`.
  - `Tolleranza::entro()`, `WorkflowException`.
- **`app/Stock`** — Giacenze e lotti.
  - `Contracts\StockSourceAdapterInterface`: `giacenzaArticolo()`, `giacenzaTotale()`,
    `lottiDisponibiliFifo()`.
  - `Contracts\LottoSemilavoratoSourceInterface`: `esisteLotto()` (change #3) — punto di estensione
    per riconoscere un lotto di semilavorato "già esistente"; implementazione di default
    `LottiProdottoSemilavoratoSource` (verifica in `lotti_prodotto` su articolo+lotto), selezionata
    da `config('mes.semilavorato.sorgente_lotti')`.
  - `SqlServerStockAdapter` (query su `MagProgrArticoli`/`MagProgrLotto`), `FixtureStockAdapter`
    (dati in memoria/JSON), `FifoAllocator::proponi()`/`totale()` (statico), `StockLotto` (DTO).
- **`app/Export`** — Export.
  - `Contracts\ExportTemplateInterface`; template `EsolverVersamentiCsvTemplate` (tracciato reale ESOLVER,
    versamenti); `EsportazioneService` con template raggruppati per **gestionale** (`esolver`, `omni`):
    `gestionaliConfigurati()`, `genera($ordine,$gestionale)`, `esporta($ordine,$gestionale)`. Restano nel
    repo (non piu' registrati) i template generici `ConsumiLottiCsvTemplate`/`VersamentiCsvTemplate`/`TracciatoJsonTemplate`/`CsvWriter`.
- **`app/Tracciabilita`** — Tracciabilità lotto dal gestionale (§6-bis).
  - `Contracts\MovimentiLottoSourceInterface` (`consumiPerProdotti()`, `carichiPerLotti()`);
    `SqlServerMovimentiAdapter` (query `MovimMagLotto`+`MovimMagazzino` su `sqlsrv_gestionale`),
    `FixtureMovimentiAdapter` (dati in memoria per dev/test); `MovimentoLotto` (DTO);
    `TracciabilitaService::albero($lotto)` ricostruisce carichi/scarichi risalendo la distinta (BFS).
  - Fonte reale: `MovimMagLotto` (dettaglio lotto: `CodArt`+`RifLottoAlfanum`, e `RifLottoPFAlfanum`=lotto
    prodotto) unita a `MovimMagazzino` (testata) su `DBGruppo+IdDocumento+IdRigaDoc+IdRigaMag`.
    Carico/scarico = `TipoMovMag` (2=carico "Carico da produzione", 3=scarico "Consumo per produzione").
    Ricorsione via `RifLottoPFAlfanum` -> `RifLottoAlfanum`. Config in `config('mes.tracciabilita')`.
  - UI: pagina `Tracciabilita/Index` (rotta `tracciabilita.index`, `can:vedere-genealogia`): cerca per
    lotto del finito, mostra l'albero della distinta risalita + tabella movimenti.
  - **Export Omni** (`OmniExport::csv()` + rotta `tracciabilita.omni`, bottone "Scarica per Omni"):
    genera il file d'importazione nel formato reale aziendale — **una riga per lotto di produzione**
    (finito + semilavorati), i componenti in **orizzontale** come `lotto*quantità` (decimale con
    virgola), colonne `[data] ; lotto ; comp1 ; comp2 ; …`, separatore `;`. Config `mes.export.omni`.
- **`app/Enums`** — `RuoloUtente` (con `label()`, `usaPin()`, e i metodi di permesso descritti in §8),
  `StatoOrdine`, `StatoFase`, `TipoArticolo`, `OrigineOrdine` (tutti string-backed, con `label()`).
- **`app/Support\LogEventi::registra()`** — helper che scrive righe in `log_eventi`.
- **`app/Http/Controllers/Produzione`** — `ChiusuraController` (area backoffice, change #4): `index()`
  (elenco ordini da chiudere), `chiusuraMassiva()` (vista distinta esplosa) e `chiudi()` (esegue la
  chiusura massiva via `ChiusuraMassivaService`).
- **`app/Http/Middleware`** — `EnsureRuolo` (alias `ruolo`, non più usato nelle rotte dopo la change #1:
  l'area operatore/sync ora usa il gate `can:avanzare-produzione`), `HandleInertiaRequests` (condivide
  `auth.user`, `auth.ruolo`, `auth.can`, `flash`, `csrf_token`).
- **`app/Rules\PinUnico`** — regola di validazione che confronta il PIN con `Hash::check` su tutti gli
  operatori attivi.
- **`app/Console/Commands`** — `BomExplodeCommand` (`bom:explode`), `GestionaleSchemaCommand`
  (`gestionale:schema`).
- **`app/Providers\MesServiceProvider`** — in `register()` fa il binding degli adapter e servizi in base
  a `config('mes.*')`; in `boot()` definisce i Gate (§8).

---

## 5. Flussi implementati

### 5.1 Creazione ordine (`OrdineController::store`)
1. `StoreOrdineRequest` valida (`authorize()` = ruolo Pianificazione o Admin; regole su
   `articolo_finito_codice`, `quantita > 0`, `numero` unique).
2. `OrdineProduzioneService::creaManuale()`: verifica `adapter->esisteArticolo()`, chiama
   `adapter->explode()`, `OrderExplosionPlanner::plan()`, crea `OrdineProduzione`, chiama
   `OrderMaterializer::materializza()`, scrive `LogEventi::registra('ordine_creato', ...)`.
3. Redirect a `ordini.show`. In errore (`RuntimeException`) torna indietro con messaggio flash.

`OrderMaterializer::materializza()` (transazione): `aggiornaCacheArticoli()` (upsert `articoli` con
`tipo`/udm/descrizione, `flag_lotto` impostato solo alla creazione), `congelaDistinta()` (insert
`distinta_righe`), `creaFasi()`, `creaMaterialiEStep()` (imposta `flag_lotto` da `Articolo::richiedeLotto()`
— dalla change #2 anche per i semilavorati; crea gli step da `TipoFase` o dal reparto di default),
`creaPrecedenze()`.

### 5.2 Login operatore via PIN (`Operatore\OperatoreAuthController`)
`showLogin()` rende `Operatore/Login`. `login()`: valida lunghezza PIN (config `mes.pin`), applica
`RateLimiter` per IP, cerca l'operatore con `trovaOperatorePerPin()` (itera gli utenti con
`ruolo=operatore`, `attivo`, `pin_hash` e usa `Hash::check`), esegue `Auth::login()`, logga
`operatore_login`, redirect a `operatore.coda`. `logout()` termina la sessione.

### 5.3 Coda operatore (`Operatore\EsecuzioneController::coda`)
Seleziona i `fase_ordine_step` non chiusi, calcola per ciascuno `workflow->stepAvviabile()`/
`motivoBlocco()`, ordina, e passa a `Operatore/Coda` le card e i `splitPendenti` (fasi
`is_nodo_condiviso` chiuse con `split_completato = false`). Il filtro per reparto si applica **solo se
l'utente è vincolato ai reparti** (`ruolo->vincolatoAiReparti()`, cioè l'Operatore); il Backoffice
vede tutte le fasi/step di qualunque reparto (change #1) e la UI lo segnala (`tutti_reparti`).

### 5.4 Esecuzione fase (`Operatore\EsecuzioneController`)
`assicuraReparto()` verifica il reparto **solo per gli utenti vincolati** (Operatore); il Backoffice
opera su qualunque step (change #1).
- `show()`: carica step/fase/materiali; `materialePerUi()` aggiunge, per le **materie prime** a lotto,
  `giacenza_mag06`, `giacenza_totale`, `lotti_mag06` e `proposta_fifo` (`FifoAllocator::proponi()`); per
  i **componenti semilavorato** a lotto (change #2) precompila `proposta_fifo` con il `lotto_propagato`
  della fase produttrice (`fase_produttrice.lottiProdotto`). Passa anche `richiede_lotto_uscita`,
  `lotto_uscita`, `completata_da_stock` e `permetti_da_stock` (vero se la fase produce un lotto ed è
  ancora `da_lavorare`).
- `avvia()` → `FaseWorkflowService::avvia()`: con `lockForUpdate`, verifica `stepAvviabile()`, imposta
  step e fase `in_corso`, porta l'ordine `in_lavorazione`, logga `fase_avviata`.
- `completaDaStock()` → `FaseWorkflowService::completaDaStock()`: vedi §5.11.
- `confermaMateriale()` → `FaseWorkflowService::confermaMateriale()`: blocca se la fase è `Chiusa`;
  se `flag_lotto` richiede ≥1 lotto e verifica somma lotti vs quantità (`Tolleranza::entro()`);
  `controllaGiacenza()` **blocca su QUALSIASI articolo** se la quantità supera la giacenza sul mag. 06:
  (1) livello articolo — quantità ≤ `giacenzaArticolo` (vale anche per i lotti digitati a mano, che non
  aggirano più il blocco); (2) rifinitura per-lotto — per i lotti presenti sul 06, quantità della riga ≤
  giacenza di quel lotto. Nessun override possibile; i semilavorati interni sono esclusi (disponibilità
  governata dalla fase produttrice). Scrive `ConsumoMateriale` + righe lotto; logga `materiale_confermato`
  o `materiale_modificato` (con `precedente`/`nuovo`).
- `chiudi()` → `FaseWorkflowService::chiudiStep()`: se lo step consuma materiali, richiede che tutti i
  materiali siano confermati e che i componenti a lotto abbiano righe lotto; chiude lo step; se tutti gli
  step sono chiusi (`FaseGate::tuttiStepChiusi()`) chiude la fase, richiede il lotto di uscita se
  l'articolo `richiedeLotto()`, scrive `LottoProdotto`, chiama `verificaCompletamentoOrdine()` (se tutte
  le fasi chiuse → ordine `completato`). Il controller reindirizza allo split se la fase chiusa è un nodo
  condiviso senza split registrato.

### 5.5 Split (`Operatore\SplitController` + `SplitService`)
`show()` verifica che la fase sia condivisa, chiusa e non ancora ripartita; mostra `destinazioni()`
(fasi padre) con quota suggerita e `quantitaDaRipartire()`. `store()` → `registra()`: valida che le
destinazioni siano fasi padre valide e che la somma quote coincida con la quantità prodotta
(`Tolleranza::entro()`); rigenera le righe `FaseSplit`, imposta `split_completato = true`, logga
`split_registrato`.

### 5.6 Genealogia (`GenealogiaController::index` + `GenealogiaService`)
Con parametro `lotto`, calcola `aRitroso()` (albero dei consumi risalendo i semilavorati e i lotti
materie prime) e `inAvanti()` (usi del lotto fino ai prodotti), passati a `Genealogia/Index`.

### 5.7 Export (`DashboardController` + `EsportazioneController` + `EsportazioneService`)
La dashboard elenca gli ordini in stato `completato` (`prontiExport`). `EsportazioneController::esporta()`
chiama `EsportazioneService::esportaZip()`: consentito solo se `stato = Completato` (altrimenti
`RuntimeException`), genera uno ZIP con i tre template, imposta `stato = Esportato` + `esportato_at`,
logga `ordine_esportato`, restituisce il file in download (`deleteFileAfterSend`).

### 5.8 Sincronizzazione offline (frontend `resources/js/offline` + `Operatore\SyncController`)
Le azioni operatore nella UI passano da `azione()` (`resources/js/offline/sync.js`): se online invia
`POST /api/sync`, altrimenti accoda in IndexedDB (`resources/js/offline/db.js`, store `coda`). `flush()`
reinvia la coda; `initSync()` registra listener `online`/`offline`/`focus`/`visibilitychange`/`pageshow`,
un `setInterval` (30s) e l'ascolto dei messaggi dal service worker; `registraBackgroundSync()` tenta la
Background Sync API. `SyncController::store()` processa un batch di azioni: per ogni `client_uuid`
verifica idempotenza su `sync_queue`, poi in `esegui()` instrada su `avvio_step`/`conferma_materiale`/
`chiusura_step`/`completa_da_stock`/`split` verso `FaseWorkflowService`/`SplitService`. `public/sw.js`
gestisce cache (precache di alcuni asset, strategie `staleWhileRevalidate`/`networkFirst`, fallback
offline) e l'evento `sync` (svuotamento coda da IndexedDB).

### 5.9 Cancellazione ordine (`OrdineController::destroy`)
Determina gli stati consentiti in base al ruolo: Admin → `Aperto` o `InLavorazione`; altri (Pianificazione)
→ solo `Aperto`. Se lo stato non è consentito torna indietro con errore. Altrimenti logga
`ordine_cancellato` (con `stato` precedente) e chiama `$ordine->delete()` (cascade DB su fasi, step,
materiali, consumi, lotti, split, distinta).

### 5.10 CRUD amministrazione (`app/Http/Controllers/Admin/*`)
`RepartoController`, `TipoFaseController` (rigenera gli step), `ArticoloConfigController`,
`UtenteController` (crea utenti "staff" con email/password o "operatore" con PIN + reparti;
`PinUnico` per l'unicità del PIN; cast `hashed` sulla password), più `HomeController` (conteggi).

### 5.11 Completamento fase da stock (`FaseWorkflowService::completaDaStock`, change #3)
Chiude una fase-nodo indicando un lotto di semilavorato **già esistente a sistema**, senza consumare
i componenti (prelievo da stock). In `DB::transaction` con `lockForUpdate`: idempotente se già chiusa;
**scarta** eventuali consumi già registrati sui componenti (non ha senso consumare se il lotto arriva da
stock); verifica l'esistenza del lotto (`lottoEsistente()`: storico `lotti_prodotto` **oppure** giacenza
reale su un qualunque magazzino). **Multi-lotto**: `completaDaStockMultiLotto()` accetta una lista di
`{lotto, quantita}` — si combinano più lotti per coprire il fabbisogno (es. 250 = 100 + 150); la quantità
prodotta è la somma dei lotti. `completaDaStock()` (lotto singolo) è ora un wrapper di questa (usato dal
flusso operatore e da `/api/sync`). **Blocco quantità (change §5.1)**: per OGNI lotto, se è a giacenza a
magazzino la quantità prelevata non può superare la giacenza di quel lotto sommata su **tutti i magazzini**
(altrimenti `WorkflowException`); i lotti solo nello storico (semilavorato prodotto internamente) non
hanno giacenza da controllare. Chiude tutti gli step e la fase (`completata_da_stock = true`), per i nodi
condivisi imposta `split_completato = true`, crea **una riga `LottoProdotto` per lotto** (genealogia
multi-lotto), propaga i lotti ai padri via `propagaLottiAiPadri()` (ripartizione proporzionale della
quantità del padre), logga `fase_completata_da_stock`, richiama `verificaCompletamentoOrdine()`. Esposto
via `operatore.step.completa-da-stock` (HTTP) e via `/api/sync` (azione `completa_da_stock`, idempotente).
In `contesto()` un figlio condiviso con `split_completato` non richiede più una riga `FaseSplit`.

### 5.12 Chiusura massiva backoffice (`Produzione\ChiusuraController` + `ChiusuraMassivaService`, change #4)
`index()` elenca gli ordini `aperto`/`in_lavorazione`. `chiusuraMassiva()` mostra `Produzione/ChiusuraMassiva`
con tutte le fasi ordinate bottom-up, proposta FIFO per le materie prime, lotto propagato per i
semilavorati, opzione "da stock" dove la fase produce un lotto. `chiudi()` valida il payload (fasi
appartenenti all'ordine) e chiama `ChiusuraMassivaService::chiudiOrdine()`: elabora le fasi in ordine
topologico bottom-up, per ciascuna produce (avvia → conferma materiali con lotti/propagazione → chiudi
con lotto prodotto → split automatico se nodo condiviso) oppure preleva da stock, tutto in un'unica
transazione (rollback totale su `WorkflowException`, con messaggio contestualizzato alla fase). La
"chiusura guidata fase per fase" riusa l'area `/operatore` (accessibile al backoffice senza vincolo di
reparto). Le fasi già chiuse vengono saltate (idempotenza), quindi la chiusura massiva funziona anche
su ordini parzialmente avanzati.

**Chiusura per singola fase (bottone "Completa fase").** Ogni card di fase ha un bottone `Completa fase`
che chiude SOLO quella fase (`POST produzione/{ordine}/fase/{fase}/chiudi` → `ChiusuraController::chiudiFase`
→ `ChiusuraMassivaService::chiudiFase`): se va a buon fine mostra un flash di successo e la card diventa
"Chiusa"; altrimenti mostra l'alert col motivo (giacenza, lotto obbligatorio, precedenze non soddisfatte).
Le precedenze restano garantite (`avvia()` blocca se i figli non sono chiusi). La UI usa `preserveState`
così in caso di errore i dati inseriti non vanno persi. La logica di dispatch stock/produzione è condivisa
con la chiusura in blocco (`elaboraFase()`). Il "preleva da stock" in questa pagina è **multi-lotto**
(`lotti_stock = [{lotto,quantita}]`, si scelgono più lotti dal picker per magazzino fino a coprire la
quantità); backward-compat con `lotto_stock` singolo.

**Fasi senza step configurati (articolo non mappato a reparto/tipo-fase).** Un nodo prodotto il cui
articolo non ha configurazione (`Reparti: n/d`) non genera step. In chiusura massiva la fase viene
comunque chiusa tramite `FaseWorkflowService::chiudiFaseDiretta()` (conferma materiali + registra il
lotto in uscita + propaga ai padri + verifica completamento ordine), con le stesse validazioni della
chiusura normale. In più, `chiudiOrdine()` esegue un controllo finale: se dopo l'elaborazione resta
anche una sola fase aperta, annulla tutto con un errore parlante — così non si verifica più il caso
"tutto ok" con l'ordine che resta in `da compilare` e alcune fasi non chiuse.

---

## 6. Interfacce utente presenti

Pagine Vue in `resources/js/Pages` (rese via Inertia; nomi = componenti):

- **Autenticazione/profilo (Breeze)**: `Auth/Login`, `Auth/ForgotPassword`, `Auth/ResetPassword`,
  `Auth/ConfirmPassword`, `Auth/VerifyEmail`; `Profile/Edit` con i partial di aggiornamento
  profilo/password ed eliminazione utente. Layout `GuestLayout`, componenti in `Components/`.
- **Dashboard** (`Dashboard.vue`, layout `AuthenticatedLayout`): conteggi ordini per stato, carico per
  reparto, tempi medi, percentuale scostamento, fasi ferme, elenco ordini pronti per l'export con form di
  export (mostrato se `auth.can.esportare`).
- **Ordini** (`Ordini/Index`, `Ordini/Create`, `Ordini/Show`): elenco con avanzamento e pulsante
  "Cancella" (visibile per gli aperti; per l'admin anche per gli "in lavorazione"); creazione con
  autocomplete articolo (chiama `ordini.cerca-articoli`); dettaglio con fasi, step, materiali e precedenze.
- **Operatore** (layout `OperatorLayout`): `Operatore/Login` (tastierino PIN, avviso offline),
  `Operatore/Coda` (card lavorabili + ripartizioni in attesa; per il backoffice segnala "tutti i
  reparti"), `Operatore/Fase` (avvio, conferma materiali con quantità/lotti, proposta FIFO, lotto
  semilavorato propagato/modificabile, avvisi giacenza, chiusura con lotto in uscita, e opzione
  "completa da stock" quando la fase produce un lotto ed è da lavorare), `Operatore/Split`
  (ripartizione con controllo somma).
- **Produzione** (layout `AuthenticatedLayout`, change #4): `Produzione/Index` (elenco ordini da
  chiudere, link a chiusura massiva e ad avanzamento guidato) e `Produzione/ChiusuraMassiva` (tutte le
  fasi bottom-up; per fase: modalità produzione/stock, materiali con lotti FIFO/propagati, lotto
  prodotto; propagazione lotti lato client; invio in blocco).
- **Genealogia** (`Genealogia/Index`): ricerca per lotto, alberi a ritroso/in avanti.
- **Amministrazione** (`Admin/Index`, `Admin/Reparti`, `Admin/TipiFase`, `Admin/ArticoliConfig`,
  `Admin/Utenti`): CRUD con form inline.
- Nav in `AuthenticatedLayout.vue`: mostra i link (Dashboard/Ordini/Produzione/Genealogia/Admin) in
  base a `auth.can.*` (il link "Produzione" con `auth.can.avanzareProduzione`).

---

## 7. Integrazioni esterne

- **MySQL**: connessione `mysql` (default), usata da tutti i modelli.
- **SQL Server (gestionale)**: connessione `sqlsrv_gestionale` (`config/database.php`), sola lettura,
  usata da:
  - `SqlServerBomAdapter` — CTE ricorsiva su `DBaseVersioni`/`DBaseRighe` con `WITH (NOLOCK)`
    (`queryEsplosione()`), più `esisteArticolo()`/`cercaArticoli()`.
  - `SqlServerStockAdapter` — `MagProgrArticoli` (`giacenzaArticolo()`, `giacenzaTotale()`) e
    `MagProgrLotto` (`lottiDisponibiliFifo()`), filtrando su `CodMag` = valore config (`06`); nomi
    tabelle/colonne e campo FIFO presi da `config('mes.stock')`.
  - `GestionaleSchemaCommand` (`gestionale:schema`) interroga `INFORMATION_SCHEMA.COLUMNS`.
  - La scelta tra adapter reale e fixture è governata da `config('mes.bom_adapter')` /
    `config('mes.stock.adapter')` in `MesServiceProvider::register()`.
- **Export su file (per gestionale)**: dalla dashboard, per ogni ordine completato ci sono due bottoni
  — **Export ESOLVER** e **Export Omni** (`POST /export/{ordine}/{gestionale}`). ESOLVER genera
  `esolver_{numero}.csv` (`EsolverVersamentiCsvTemplate`): versamenti a magazzino nel formato reale
  (riga 1 intestazione fissa `10;20;150;180;270;260;140;`, poi `causale;data;articolo;qta(virgola);lotto;01;850;`,
  separatore `;` con `;` finale, **senza BOM**, CRLF). Le costanti sono in `config('mes.export.esolver')`.
  Omni è in attesa del tracciato reale (nessun template → bottone disabilitato). L'export **non blocca**
  l'altro gestionale: marca l'ordine "esportato" ma resta ri-esportabile/ri-scaricabile (in dashboard
  restano visibili anche gli ordini `esportato`). I template generici CSV/JSON restano nel repo ma non
  sono più registrati.
- **Fixture di sviluppo/test**: `tests/fixtures/bom/*.json` (generati da
  `tests/fixtures/bom/_src/build_fixtures.php`) e `tests/fixtures/stock/giacenze.json`.
- **PWA**: `public/manifest.webmanifest`, `public/sw.js`, `public/icons/icon.svg`, registrati in
  `resources/js/app.js` e `resources/views/app.blade.php`.

---

## 8. Autenticazione, ruoli e permessi

- **Guardia**: sessione web di Laravel. Login "staff" via email/password (Breeze,
  `Auth\AuthenticatedSessionController`); login operatore via PIN (`Operatore\OperatoreAuthController`),
  entrambi sullo stesso guard `web`.
- **Redirect post-login**: `AuthenticatedSessionController::store()` e la rotta `/` reindirizzano a
  `RuoloUtente::rottaHome()` (operatore → `operatore.coda`, admin → `admin.index`, altri → `dashboard`).
- **Ruoli** (`App\Enums\RuoloUtente`): `Operatore`, `Backoffice`, `Pianificazione`, `Admin`. Metodi di
  permesso: `puoConfigurare()` (solo Admin), `puoGestireOrdini()` (Admin+Pianificazione),
  `puoEsportare()` (Admin+Backoffice), `vedeDashboard()` (Admin+Backoffice+Pianificazione),
  `vedeGenealogia()` (= `vedeDashboard()`), `puoAvanzareProduzione()` (Operatore+Backoffice, change #1),
  `vincolatoAiReparti()` (solo Operatore); `permessi()` li serializza per il frontend (incl.
  `avanzareProduzione`, `vincolatoAiReparti`).
- **Gate** (`MesServiceProvider::boot()`): `configurare`, `gestire-ordini`, `esportare`,
  `vedere-dashboard`, `vedere-genealogia`, `avanzare-produzione`, definiti sui metodi di `RuoloUtente`.
- **Protezione rotte** (`routes/web.php`):
  - `/admin/*` → `can:configurare`.
  - `/dashboard` → `can:vedere-dashboard`; `/genealogia` → `can:vedere-genealogia`.
  - `/export/{ordine}` → `can:esportare`.
  - `/ordini*` (index, create, store, show, destroy, cerca-articoli) → `can:gestire-ordini`.
  - Area `/operatore/*` (esclusi login/pin-login pubblici) e `/api/sync` → `can:avanzare-produzione`
    (Operatore+Backoffice, change #1). Il vincolo per reparto è applicato nei controller, non nel gate.
  - `/produzione/*` (index, chiusura massiva, chiusura per singola fase `chiudi-fase`) → `can:avanzare-produzione` (change #4).
- **Registrazione pubblica**: le rotte `register` sono assenti da `routes/auth.php`; non esistono
  `RegisteredUserController` né `Auth/Register.vue`. La creazione utenti avviene da
  `Admin\UtenteController`.
- **CSRF**: `bootstrap/app.php` esclude `api/sync` dalla verifica CSRF.
- Sono presenti i flussi Breeze di reset password, conferma password e verifica email
  (`app/Http/Controllers/Auth/*`, `routes/auth.php`).

---

## 9. Test presenti

Cartella `tests/`. Configurazione in `phpunit.xml`: connessione `mysql`, DB `mes_lievitati_test`,
`MES_BOM_ADAPTER=fixture`, `MES_STOCK_ADAPTER=fixture`.

- **Unit (non usano il database)**:
  - `Unit/Bom/BomExplosionTest` — esplosione via `FixtureBomAdapter` e helper di `BomExplosion`.
  - `Unit/Ordini/OrderExplosionPlannerTest` — generazione `PhasePlan`.
  - `Unit/Produzione/FaseGateTest` — regole di `FaseGate`.
  - `Unit/Produzione/TolleranzaTest` — `Tolleranza::entro`.
  - `Unit/Stock/FifoAllocatorTest` — `FifoAllocator::proponi`.
- **Feature (usano il database, `RefreshDatabase`)**:
  - `Feature/CreazioneOrdineTest`, `Feature/EsecuzioneOperatoreTest`, `Feature/SplitTest`,
    `Feature/GenealogiaTest`, `Feature/SyncTest`, `Feature/ExportTest`, `Feature/StockGiacenzaTest`,
    `Feature/CorrezioneMaterialeTest`, `Feature/CancellazioneOrdineTest`, `Feature/RuoloAccessoTest`
    (aggiornato: accesso avanzamento backoffice/operatore, vincolo reparto), `Feature/Admin/AdminUtentiTest`.
  - Change #1–#4: `Feature/PropagazioneLottoTest` (lotto semilavorato propagato sulla riga-componente,
    catena ≥3 livelli, genealogia), `Feature/CompletamentoDaStockTest` (lotto esistente → fase chiusa
    senza consumo; rifiuto del lotto inesistente), `Feature/ChiusuraMassivaTest` (chiusura in blocco
    bottom-up, validazioni che bloccano con rollback, HTTP backoffice, split automatico nodo condiviso).
  - Test Breeze: `Feature/Auth/*` (autenticazione, reset/conferma password, verifica email),
    `Feature/ProfileTest`.
- **Esecuzione**: `php artisan test` oppure `composer test`; i test unit girano senza database.
  Diversi test feature invocano `MesConfigSeeder` nel `setUp()`.

---

## 10. Elementi incompleti o ambigui

- **Seeder di default**: `DatabaseSeeder::run()` crea un solo utente `Test User`
  (`database/seeders/DatabaseSeeder.php`) e **non** richiama `MesConfigSeeder`. La configurazione MES di
  base e gli utenti demo esistono solo in `MesConfigSeeder`, che va eseguito esplicitamente
  (`db:seed --class=...MesConfigSeeder`); nel codice è invocato solo dai test feature.
- **Rotte POST operatore non richiamate dal frontend**: `operatore.step.avvia`,
  `operatore.materiale.conferma`, `operatore.step.chiudi`, `operatore.step.completa-da-stock`,
  `operatore.split.store` sono definite in `routes/web.php` e implementate nei controller, ma le pagine
  Vue (`Operatore/Fase.vue`, `Operatore/Split.vue`) inviano le azioni tramite `azione()` →
  `POST /api/sync`. Le rotte GET `operatore.fase`, `operatore.split`, `operatore.coda`,
  `operatore.logout` sono invece usate. Esistono quindi due percorsi verso gli stessi servizi (rotte
  Inertia dedicate e `/api/sync`), ma l'area operatore usa solo `/api/sync`. L'area `Produzione/*`
  (chiusura massiva) usa invece le proprie rotte Inertia (`produzione.chiudi-massivo`), non `/api/sync`.
- **Config `mes.split_scope`**: presente in `config/mes.php` ma non letta da alcun codice; compare solo
  in un commento di `SplitService.php` (riga ~20). `SplitService` non filtra per scope.
- **Commento disallineato su `ordini.destroy`**: in `routes/web.php` (riga ~89) il commento indica
  "solo se aperto, nessuna fase avviata", mentre `OrdineController::destroy` consente all'Admin di
  cancellare anche gli ordini `in_lavorazione`.
- **Sorgente lotti semilavorato "esistenti"** (change #3): `config('mes.semilavorato.sorgente_lotti')`
  ha come unico valore implementato `'lotti_prodotto'` (default). La sorgente reale resta **[DA
  CONFERMARE]** (lotti storici e/o giacenza semilavorati su magazzino dedicato): il punto di estensione
  `LottoSemilavoratoSourceInterface` consente di aggiungerla senza toccare la logica.
- **Marcatori `PROVVISORIO` / TODO**:
  - `config/mes.php` e `SqlServerStockAdapter` segnalano che l'ordinamento FIFO usa `RifLottoNum`
    come proxy "provvisorio", in attesa di conferma del campo cronologico dal gestionale.
  - `config/mes.php` contiene commenti `TODO-DECISIONE` su tolleranza e `split_scope`.
- **Blocco giacenza (aggiornato)**: la giacenza insufficiente sul mag. 06 è **bloccata lato server per
  qualsiasi articolo** (`FaseWorkflowService::controllaGiacenza`), inclusi i lotti digitati a mano; non
  esiste più il "superamento con conferma" (rimossi `rilevaSuperamentoTotale`, l'evento
  `materiale_superamento_giacenza` e il relativo `window.confirm` in `Operatore/Fase.vue`). Il parametro
  `confermaSuperamento` di `confermaMateriale()` resta nella firma per compatibilità ma è ignorato.
- **Frontend Tailwind**: `package.json` include sia `tailwindcss ^3.2.1` (con `postcss.config.js` e
  `tailwind.config.js`) sia `@tailwindcss/vite ^4.0.0`, ma `vite.config.js` registra solo i plugin
  `laravel` e `vue` (nessun plugin `@tailwindcss/vite`).
- **Alias `@`**: le pagine Vue importano da `@/...` ma `vite.config.js` non dichiara un
  `resolve.alias` per `@`.
- **Script `post-create-project-cmd`** in `composer.json` crea `database/database.sqlite` ed esegue
  `migrate --graceful` (residuo dello skeleton), mentre `.env` usa `DB_CONNECTION=mysql`.
- **`bootstrap/app.php`**: `withRouting()` registra solo `web`, `commands`, `health`; non è registrato un
  file di rotte `api`. L'endpoint `/api/sync` è definito nel gruppo web di `routes/web.php`.
- **Esiti di `azione()` / `/api/sync`** (verificato): `resources/js/offline/sync.js` `azione()` ritorna
  `{ stato: 'ok' | 'errore' | 'accodata' }` e mappa `r.permanente` su `stato: 'errore'`;
  `SyncController::processaAzione()` restituisce per azione `ok`, `duplicato`, oppure `ok:false` con
  `permanente` true/false ed `errore`. Non esiste alcun esito `conferma_richiesta` (nessuna occorrenza
  in `sync.js` né in `SyncController`). La giacenza insufficiente è ora un errore di conferma
  (`WorkflowException`) restituito come esito di errore, non più un avviso lato client.
- **Riferimenti a sezioni `§`**: molti commenti citano numeri di sezione di un documento esterno non
  incluso nel repository; non sono verificabili dal solo codice.
