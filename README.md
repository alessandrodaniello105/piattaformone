# Piattaformone

Laravel 12 app: **Fatture in Cloud** integration, multi-tenant teams (Jetstream), Inertia + Vue 3, Reverb. Syncs clients, quotes, invoices; generates DOCX via PHPWord; FIC webhooks + OAuth per team.

---

## Status

**Working:** OAuth (per-team credentials, company selector on reconnect), FIC sync, webhooks, subscriptions, DOCX generation, Reverb. Team FIC settings UI. `oauth:logs`, `fic:*` Artisan commands. Sail + Docker.

**Rough edges:** Some feature tests expect different setup (FicSyncController 500s without auth/team context; FicWebhookController 401s — API likely behind auth). `ProcessFicWebhookTest` has Log mock expectations that need love. Vite HMR with ngrok: see `FIX_VITE_HMR.md`.

---

## TODOs

- [ ] **FIC roadmap** (`docs/FIC-MULTI-TENANT-ROADMAP.md`): Steps 4–7 still open — multi-tenant tests, migration seeder for existing creds, security (policy, validation), user docs.
- [ ] Fix/align FicSyncController & FicWebhookController feature tests (auth, env, whatever makes them green).
- [ ] Fix `ProcessFicWebhookTest` Log mocking.
- [ ] Nothing else critical; the rest is “nice to have.”

---

## Quick start

```bash
composer install && cp .env.example .env && php artisan key:generate
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail npm install && vendor/bin/sail npm run build
```

OAuth redirect runs at `/api/fic/oauth/redirect`. Configure FIC client (team settings or `.env` fallback). Use `vendor/bin/sail bin pint --dirty` before commits. You know the drill.

---

# Piattaformone

App Laravel 12: integrazione **Fatture in Cloud**, team multi-tenant (Jetstream), Inertia + Vue 3, Reverb. Sincronizza clienti, preventivi, fatture; genera DOCX con PHPWord; webhook e OAuth FIC per team.

---

## Stato

**Funziona:** OAuth (credenziali per team, selettore azienda in reconnect), sync FIC, webhook, subscription, generazione DOCX, Reverb. UI impostazioni FIC per team. Comandi `oauth:logs`, `fic:*`. Sail + Docker.

**Da sistemare:** Qualche test feature presuppone un altro setup (FicSyncController 500 senza auth/team; FicWebhookController 401 — API dietro auth). `ProcessFicWebhookTest` ha mock su Log da aggiustare. HMR Vite con ngrok: vedi `FIX_VITE_HMR.md`.

---

## TODO

- [ ] **Roadmap FIC** (`docs/FIC-MULTI-TENANT-ROADMAP.md`): Step 4–7 ancora aperti — test multi-tenant, seeder migrazione credenziali, security (policy, validazione), documentazione utente.
- [ ] Allineare/far passare i test feature di FicSyncController e FicWebhookController (auth, env, ecc.).
- [ ] Sistemare il mocking di Log in `ProcessFicWebhookTest`.
- [ ] Nient’altro di bloccante; il resto è “would be nice”.

---

## Avvio rapido

```bash
composer install && cp .env.example .env && php artisan key:generate
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail npm install && vendor/bin/sail npm run build
```

Redirect OAuth: `/api/fic/oauth/redirect`. Configura app FIC (impostazioni team o fallback `.env`). Prima dei commit: `vendor/bin/sail bin pint --dirty`. Il resto è routine.
