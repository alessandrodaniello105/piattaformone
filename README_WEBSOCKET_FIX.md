# 🔧 Fix: WebSocket Connection Failed con ngrok + Laravel Reverb

## 📌 Problema Risolto

**Errore originale:**
```
WebSocket connection to 'wss://lesa-stripier-elena.ngrok-free.dev/app/phavh9yomtoejn0igykw?protocol=7&client=js&version=8.4.0&flash=false' failed
```

**Causa:** 
Le variabili d'ambiente `VITE_REVERB_*` non erano configurate nel file `.env`, quindi Echo non riusciva a connettersi al server Reverb.

## ✅ Modifiche Effettuate

### 1. Aggiornato `resources/js/bootstrap.js`
- ✅ Aggiunta configurazione `authEndpoint` per il reverse proxy Apache
- ✅ Modificato `disableStats: true` per ridurre il carico

### 2. Creati Script Helper

#### `check-reverb.sh`
Script per verificare la configurazione Reverb.

**Uso:**
```bash
./check-reverb.sh
```

**Controlla:**
- Variabili d'ambiente nel `.env`
- Stato server Reverb
- Configurazione broadcasting
- Valori attuali delle variabili

#### `update-ngrok-url.sh`
Script per aggiornare automaticamente l'URL ngrok nel `.env`.

**Uso:**
```bash
./update-ngrok-url.sh
```

**Quando:**
- Ogni volta che riavvii ngrok (l'URL cambia con account free)

**Funzionalità:**
- Recupero automatico URL da ngrok API (http://localhost:4040)
- Aggiornamento automatico di tutte le variabili REVERB_* e VITE_REVERB_*
- Backup automatico del `.env` prima delle modifiche

### 3. Documentazione Aggiornata

#### `SOLUZIONE_WEBSOCKET.md`
Guida step-by-step per risolvere il problema.

#### `REVERB_SETUP.md`
Guida completa per configurare Laravel Reverb con troubleshooting esteso.

## 🚀 Come Usare (Per L'Utente)

### Setup Iniziale (Una Volta)

1. **Verifica/Aggiungi le variabili nel `.env`:**

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=123456
REVERB_APP_KEY=your-app-key-here
REVERB_APP_SECRET=your-app-secret-here

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

REVERB_HOST=lesa-stripier-elena.ngrok-free.dev
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

2. **Riavvia i container:**
```bash
vendor/bin/sail down
vendor/bin/sail up -d
```

### Uso Quotidiano

1. **In Terminale 1 - Avvia Reverb:**
```bash
vendor/bin/sail artisan reverb:start
```
Lascia aperto.

2. **In Terminale 2 - Avvia Vite:**
```bash
vendor/bin/sail npm run dev
```
Lascia aperto.

3. **Nel Browser:**
- Vai alla dashboard
- Verifica il pallino verde "Echo connesso"
- I webhook di FIC appariranno in tempo reale

### Quando Riavvii ngrok

L'URL ngrok cambia ad ogni riavvio con account free. Quando riavvii ngrok:

```bash
# 1. Aggiorna automaticamente l'URL nel .env
./update-ngrok-url.sh

# 2. Riavvia i container
vendor/bin/sail down && vendor/bin/sail up -d

# 3. Riavvia Reverb (terminale 1)
vendor/bin/sail artisan reverb:start

# 4. Riavvia Vite (terminale 2)
vendor/bin/sail npm run dev
```

## 🔍 Troubleshooting

### Problema: Echo non si connette

**Soluzione:**
```bash
# 1. Verifica configurazione
./check-reverb.sh

# 2. Verifica nel browser (Console DevTools)
console.log(import.meta.env.VITE_REVERB_HOST);

# Se è undefined:
# - Ferma Vite (Ctrl+C)
# - Verifica che VITE_REVERB_* siano nel .env
# - Riavvia Vite
```

### Problema: Reverb non parte

**Errore:** `Address already in use (EADDRINUSE)`

**Soluzione:**
```bash
# Trova il processo che usa la porta 8080
vendor/bin/sail exec laravel.test lsof -ti:8080 | xargs kill -9

# Riavvia Reverb
vendor/bin/sail artisan reverb:start
```

### Problema: WebSocket 101 Switching Protocols fallito

**Causa:** Il reverse proxy Apache non sta inoltrando correttamente le richieste WebSocket.

**Soluzione:**
- Il file `docker/apache/000-default.conf` è già configurato correttamente
- Verifica che Apache sia in esecuzione: `vendor/bin/sail exec laravel.test service apache2 status`

## 📚 File di Riferimento

- `SOLUZIONE_WEBSOCKET.md` - Guida step-by-step per risolvere il problema
- `REVERB_SETUP.md` - Guida completa setup + troubleshooting
- `check-reverb.sh` - Script verifica configurazione
- `update-ngrok-url.sh` - Script aggiornamento URL ngrok

## 🎯 Architettura della Soluzione

```
Browser (HTTPS)
    ↓
ngrok (lesa-stripier-elena.ngrok-free.dev:443)
    ↓
Container Docker - Apache (:80)
    ↓ (reverse proxy /app/)
Container Docker - Laravel Reverb (:8080)
    ↓
Broadcasting/Events
    ↓
Vue Dashboard (Echo client)
```

Il reverse proxy Apache (`docker/apache/000-default.conf`) è configurato per:
1. Ricevere richieste WebSocket da ngrok (wss://)
2. Convertirle in richieste ws:// locali verso Reverb (porta 8080)
3. Gestire l'upgrade HTTP → WebSocket

## ✨ Risultato Finale

Quando tutto funziona:
- ✅ Echo si connette automaticamente all'apertura della dashboard
- ✅ Pallino verde "Echo connesso" visibile
- ✅ I webhook di FIC appaiono in tempo reale nella dashboard
- ✅ Logs nel terminale Reverb mostrano le connessioni attive
- ✅ Console browser mostra "Echo connected!"

## 📝 Note Tecniche

### Perché le variabili VITE_* sono necessarie?

Vite (il bundler frontend) ha un comportamento speciale:
- Le variabili d'ambiente NON sono disponibili a runtime nel browser
- Solo le variabili che iniziano con `VITE_` vengono "embedded" nel bundle JavaScript
- Questo avviene durante la compilazione (`npm run dev` o `npm run build`)
- Per questo motivo, dopo aver aggiunto/modificato variabili VITE_*, devi riavviare Vite

### Perché riavviare i container Docker?

I container Docker caricano le variabili d'ambiente dal file `.env` solo all'avvio.
Modifiche al `.env` mentre i container sono in esecuzione NON vengono caricate automaticamente.

### ngrok e URL dinamici

Con l'account ngrok gratuito:
- L'URL cambia ad ogni riavvio
- Lo script `update-ngrok-url.sh` automatizza l'aggiornamento nel `.env`
- In alternativa, considera ngrok Pro per URL fisso

## 🤝 Contributi

Questa soluzione risolve il problema di connessione WebSocket con:
- ✅ Laravel 12
- ✅ Laravel Reverb
- ✅ Laravel Echo + Pusher.js
- ✅ Vue 3 + Inertia v2
- ✅ Docker (Laravel Sail)
- ✅ ngrok (account free)
- ✅ Apache con reverse proxy

## 📞 Supporto

Per domande o problemi:
1. Leggi `SOLUZIONE_WEBSOCKET.md`
2. Esegui `./check-reverb.sh` per diagnostica
3. Controlla `REVERB_SETUP.md` per troubleshooting avanzato

---

**Data:** 2026-01-21
**Versioni:** Laravel 12, Reverb latest, Echo 8.4.0
