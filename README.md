# Piattaformone 🚀

A modern Laravel 12 application that brings **Fatture in Cloud** (Italy's leading invoicing platform) into the world of multi-tenant team collaboration. Built with Jetstream, Inertia.js, Vue 3, and Reverb for real-time updates.

### What does it do?

Think of it as a bridge between your team's workflow and Fatture in Cloud. Each team gets their own FIC connection, syncs their invoicing data (clients, suppliers, quotes, invoices), receives real-time webhook updates, and can even generate custom documents from templates. All wrapped in a clean, modern interface.

---

## 📊 Current Status

### ✅ What's Working Well

The core is solid:
- **Multi-tenant OAuth**: Each team connects their own FIC account with OAuth credentials stored per-team
- **Real-time sync**: Clients, suppliers, invoices, and quotes stay up-to-date
- **Webhooks**: Live updates from FIC flow through Reverb to your UI
- **Document generation**: Upload DOCX templates, map variables, generate filled documents
- **Rich CLI tools**: `oauth:logs`, `fic:sync`, and friends make debugging a breeze
- **Docker ready**: Sail configuration for instant dev environment

### 🔧 Known Rough Edges

A few tests need some attention:
- `FicSyncController` and `FicWebhookController` feature tests expect auth/team context (currently returning 500/401)
- `ProcessFicWebhookTest` has some Log mock expectations that could use refinement
- Vite HMR with ngrok needs special configuration (documented in `FIX_VITE_HMR.md`)

Nothing blocking, just housekeeping.

---

## 📋 Roadmap

Check `docs/FIC-MULTI-TENANT-ROADMAP.md` for the full picture. Here's what's on deck:

- [ ] **Complete multi-tenant tests** - Ensure everything works smoothly when multiple teams are active
- [ ] **Migration seeder** - Help existing installations move credentials to the new per-team model
- [ ] **Security hardening** - Policies, validation, the usual suspects
- [ ] **User documentation** - Make onboarding smooth for new team members

Everything else? Nice-to-have territory.

---

## 🚀 Quick Start

Get up and running in under 5 minutes:

```bash
# Install dependencies and set up environment
composer install && cp .env.example .env && php artisan key:generate

# Start the Docker containers
vendor/bin/sail up -d

# Set up the database
vendor/bin/sail artisan migrate

# Build frontend assets
vendor/bin/sail npm install && vendor/bin/sail npm run build
```

### Configure Fatture in Cloud

1. Create a FIC OAuth app (or use existing credentials)
2. Set up credentials either:
   - **Per team** in the team settings UI (recommended for multi-tenant)
   - **Global fallback** in your `.env` file

The OAuth redirect URI is `/api/fic/oauth/redirect`.

### Before you commit

Run Pint to keep the code clean:
```bash
vendor/bin/sail bin pint --dirty
```

---

## 🛠️ Tech Stack

- **Backend**: Laravel 12, PostgreSQL, Redis
- **Frontend**: Vue 3, Inertia.js, Tailwind CSS
- **Real-time**: Laravel Reverb (WebSockets)
- **Dev Environment**: Laravel Sail (Docker)
- **Document Processing**: PHPWord
- **Testing**: PHPUnit, Laravel's test suite

---

## 📚 Documentation

More detailed docs in the `docs/` folder and `CLAUDE.md` for AI-assisted development guidelines.

---

# Piattaformone 🚀

Un'applicazione Laravel 12 moderna che integra **Fatture in Cloud** (la principale piattaforma di fatturazione italiana) nel mondo della collaborazione multi-tenant. Costruita con Jetstream, Inertia.js, Vue 3 e Reverb per aggiornamenti in tempo reale.

### Cosa fa?

Pensa a questa app come un ponte tra il flusso di lavoro del tuo team e Fatture in Cloud. Ogni team ottiene la propria connessione FIC, sincronizza i dati di fatturazione (clienti, fornitori, preventivi, fatture), riceve aggiornamenti webhook in tempo reale e può persino generare documenti personalizzati da template. Il tutto in un'interfaccia pulita e moderna.

---

## 📊 Stato Attuale

### ✅ Cosa Funziona Bene

Il cuore dell'applicazione è solido:
- **OAuth multi-tenant**: Ogni team connette il proprio account FIC con credenziali OAuth memorizzate per team
- **Sincronizzazione real-time**: Clienti, fornitori, fatture e preventivi sempre aggiornati
- **Webhook**: Gli aggiornamenti live da FIC fluiscono attraverso Reverb verso l'interfaccia
- **Generazione documenti**: Carica template DOCX, mappa le variabili, genera documenti compilati
- **Strumenti CLI completi**: `oauth:logs`, `fic:sync` e altri comandi per facilitare il debugging
- **Pronto per Docker**: Configurazione Sail per ambiente di sviluppo istantaneo

### 🔧 Aspetti da Rifinire

Alcuni test necessitano di attenzione:
- I test feature di `FicSyncController` e `FicWebhookController` si aspettano contesto auth/team (attualmente restituiscono 500/401)
- `ProcessFicWebhookTest` ha alcune aspettative sui mock di Log che potrebbero essere migliorate
- Vite HMR con ngrok richiede una configurazione speciale (documentata in `FIX_VITE_HMR.md`)

Niente di bloccante, solo manutenzione ordinaria.

---

## 📋 Roadmap

Consulta `docs/FIC-MULTI-TENANT-ROADMAP.md` per il quadro completo. Ecco cosa c'è in programma:

- [ ] **Completare i test multi-tenant** - Assicurare che tutto funzioni senza intoppi quando più team sono attivi
- [ ] **Seeder di migrazione** - Aiutare le installazioni esistenti a migrare le credenziali al nuovo modello per-team
- [ ] **Rafforzamento della sicurezza** - Policy, validazione, i soliti sospetti
- [ ] **Documentazione utente** - Rendere l'onboarding fluido per i nuovi membri del team

Tutto il resto? Territorio "nice-to-have".

---

## 🚀 Avvio Rapido

Sii operativo in meno di 5 minuti:

```bash
# Installa le dipendenze e configura l'ambiente
composer install && cp .env.example .env && php artisan key:generate

# Avvia i container Docker
vendor/bin/sail up -d

# Configura il database
vendor/bin/sail artisan migrate

# Compila le risorse frontend
vendor/bin/sail npm install && vendor/bin/sail npm run build
```

### Configura Fatture in Cloud

1. Crea un'app OAuth FIC (o usa credenziali esistenti)
2. Configura le credenziali in uno dei due modi:
   - **Per team** nell'interfaccia impostazioni team (consigliato per multi-tenant)
   - **Fallback globale** nel file `.env`

L'URI di redirect OAuth è `/api/fic/oauth/redirect`.

### Prima di fare commit

Esegui Pint per mantenere il codice pulito:
```bash
vendor/bin/sail bin pint --dirty
```

---

## 🛠️ Stack Tecnologico

- **Backend**: Laravel 12, PostgreSQL, Redis
- **Frontend**: Vue 3, Inertia.js, Tailwind CSS
- **Real-time**: Laravel Reverb (WebSockets)
- **Ambiente di Sviluppo**: Laravel Sail (Docker)
- **Elaborazione Documenti**: PHPWord
- **Testing**: PHPUnit, suite di test Laravel

---

## 📚 Documentazione

Documentazione più dettagliata nella cartella `docs/` e in `CLAUDE.md` per le linee guida di sviluppo assistito da AI.
