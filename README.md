# MES Lievitati

Manufacturing Execution System per la tracciabilità della produzione di lievitati (panettoni,
colombe, torroni). Legge anagrafica e distinte base dal gestionale **Passepartout/Mexal (SQL Server,
sola lettura)**, genera ordini di produzione esplodendo le distinte, guida gli operatori
nell'esecuzione delle fasi su tablet, e produce i file per la reimportazione nei gestionali.

Vedi `PROMPT_BUILD_CLAUDE_CODE.md` (specifica) per il dominio completo. Riferimenti `§` = sezioni della specifica.

## Stack

- **Backend:** PHP 8.3 + Laravel 13
- **DB applicativo:** MySQL 8
- **DB gestionale (sorgente):** SQL Server (sola lettura, `WITH (NOLOCK)`) — estensioni `sqlsrv`/`pdo_sqlsrv`
- **Frontend:** Inertia.js + Vue 3 (SPA), Vite, Tailwind
- **Hosting produzione:** IIS su Windows Server (`public/web.config` incluso)

## Architettura (punti chiave)

- **Adapter distinte disaccoppiato** (`app/Bom`): `BomSourceAdapterInterface` con `SqlServerBomAdapter`
  (query di esplosione ricorsiva §4.3) e `FixtureBomAdapter` (JSON, per sviluppo/test senza gestionale).
  Si sceglie con `MES_BOM_ADAPTER` (`sqlsrv` | `fixture`).
- **Logica di dominio pura e testabile senza DB** (`app/Ordini`): `OrderExplosionPlanner` trasforma la
  distinta esplosa in fasi/materiali/precedenze; `OrderMaterializer` la persiste in MySQL.
- **Modello centrale:** una fase = un nodo prodotto della distinta; i nodi condivisi generano UNA sola
  fase (poi ripartita con lo *split*, §5-bis). Vedi la specifica §3.

## Sviluppo in locale

> Nota: l'ambiente di sviluppo NON è quello di produzione. Il DB viene creato sul server (vedi sotto).
> `MES_BOM_ADAPTER=fixture` consente di sviluppare senza gestionale né driver SQL Server.

```bash
composer install
npm install
cp .env.example .env   # oppure usa il .env già presente in dev
php artisan key:generate
npm run build          # oppure: npm run dev (hot reload)
```

### Comando di verifica dell'adapter distinte

```bash
php artisan bom:explode ASSPAN01 --qta=10          # albero + riepilogo fasi
php artisan bom:explode PAN0104  --adapter=fixture
```

### Test

I test di dominio (esplosione, planner) NON richiedono database:

```bash
php artisan test tests/Unit
```

I test *feature* (`tests/Feature/CreazioneOrdineTest.php`) richiedono un database MySQL di test
(`mes_lievitati_test`, vedi `phpunit.xml`) e girano sul server:

```bash
php artisan test
```

### Utenti demo (dopo il seed)

```bash
php artisan db:seed --class=Database\\Seeders\\MesConfigSeeder
```

| Ruolo | Accesso |
|---|---|
| Admin | admin@lievitati.local / password |
| Pianificazione | pianificazione@lievitati.local / password |
| Backoffice | backoffice@lievitati.local / password |
| Operatori (PIN) | Mario 1234 (Impasto), Luigi 2345 (Forno), Anna 3456 (Confez.), Sara 4567 (tutti) |

## Deploy su server aziendale (IIS) — §2-bis

1. **Prerequisiti sul server** (attività IT, una tantum):
   - PHP 8.3 per Windows (Thread Safe) + **FastCGI** in IIS (consigliato *PHP Manager for IIS*).
   - Estensioni PHP: `pdo_sqlsrv`, `sqlsrv` (driver Microsoft, da installare a parte), più
     `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `curl`, `gd`, `zip`.
   - Modulo **URL Rewrite** di IIS (per `public/web.config`).
   - MySQL 8 raggiungibile; utente applicativo con permessi solo sul DB dell'app.
   - Utente SQL Server per il gestionale con **solo `db_datareader`** (§4.2, criterio 10).
   - **HTTPS** (certificato CA interna o self-signed fidato sui tablet) — obbligatorio per la PWA (§2-bis.2).

2. **Pubblicazione applicazione:**
   - Copiare il progetto sul server; il sito IIS deve puntare come *physical path* alla cartella `public/`.
   - Creare `.env` di produzione partendo da `.env.example` (impostare `APP_URL=https://...`,
     `SESSION_SECURE_COOKIE=true`, credenziali MySQL e gestionale, `MES_BOM_ADAPTER=sqlsrv`).
   - Comandi:
     ```bash
     composer install --no-dev --optimize-autoloader
     php artisan key:generate
     php artisan migrate --force
     php artisan db:seed --class=Database\\Seeders\\MesConfigSeeder   # config reparti/fasi + utenti iniziali
     npm ci && npm run build
     php artisan config:cache && php artisan route:cache
     ```
   - Permessi di scrittura su `storage/` e `bootstrap/cache/` per l'Application Pool IIS.

3. **Verifica connessione gestionale:** `php artisan bom:explode <CODICE_REALE> --adapter=sqlsrv`.

## Stato di avanzamento (piano §14)

- [x] Fase 0 — Artefatti IIS (`public/web.config`), `.env` HTTPS-ready
- [x] Fase 1 — Fondamenta, schema completo, adapter distinte, `bom:explode`, test
- [x] Fase 2 — Creazione ordine + generazione fasi/materiali/precedenze (planner testato)
- [x] Fase 3 — Esecuzione operatore (login PIN, coda di reparto, avvio/conferma/chiusura, gating precedenze)
- [x] Fase 4 — Split nodi condivisi (schermata ripartizione, sblocco fasi padre, tolleranza)
- [x] Fase 5 — Lotti e multi-lotto, lotto in uscita, genealogia (avanti/indietro, propagata via split)
- [x] Fase 6 — Offline: PWA (manifest + service worker), coda IndexedDB, `/api/sync` idempotente,
      Background Sync + fallback polling, indicatore online/offline. **Da validare su server HTTPS/tablet.**
- [x] Fase 7 — Dashboard KPI (§9), motore export a template (CSV consumi/versamenti + JSON tracciato),
      consultazione genealogia, ruoli applicati via middleware su tutte le rotte

### Note e limiti noti
- **Formati export**: template di esempio (backflush consumi, versamenti, tracciato JSON). I tracciati
  reali del committente si aggiungono implementando `ExportTemplateInterface` senza toccare il core (§10).
- **Offline (Fase 6)**: infrastruttura completa; la verifica end-to-end (service worker, Background Sync,
  caduta rete) va fatta sul server in HTTPS o su `localhost` (i SW non girano in dev http).
- **Admin config UI**: reparti/fasi/mapping/utenti sono creati dal `MesConfigSeeder`; una UI admin
  dedicata e' un'estensione futura (i permessi sono gia' applicati). Icone PWA in SVG: valutare PNG 192/512.
