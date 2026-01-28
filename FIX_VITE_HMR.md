# 🔧 Fix: Vite HMR non funziona con ngrok (pagina bianca)

## 📋 Problema

**Sintomo:**
- `npm run build` → tutto funziona ✅
- `npm run dev` → pagina bianca ❌
- Errore console: `GET http://localhost:5173/@vite/client net::ERR_CONNECTION_REFUSED`

**Causa:**
Vite HMR cerca di caricare asset da `http://localhost:5173` ma:
1. Stai usando ngrok con HTTPS → errore Mixed Content
2. La porta Docker è mappata su 5174, non 5173
3. Vite non sa che deve usare l'URL ngrok

## ✅ Soluzione

### Passo 1: Aggiungi queste variabili al file `.env`

Apri il file `.env` e aggiungi/aggiorna:

```env
# URL principale (ngrok) - AGGIORNA CON IL TUO URL NGROK
APP_URL=https://lesa-stripier-elena.ngrok-free.dev

# Vite HMR Configuration (IMPORTANTE!)
VITE_HMR_HOST=lesa-stripier-elena.ngrok-free.dev
VITE_HMR_CLIENT_PORT=443

# Porta Vite (standard)
VITE_PORT=5173

# Reverb (se non le hai già)
REVERB_HOST=lesa-stripier-elena.ngrok-free.dev
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Passo 2: Riavvia i container Docker

```bash
vendor/bin/sail down
vendor/bin/sail up -d ngrok
```

### Passo 3: Avvia Reverb (Terminale 1)

```bash
vendor/bin/sail artisan reverb:start
```

Lascia aperto questo terminale.

### Passo 4: Avvia Vite (Terminale 2)

```bash
vendor/bin/sail npm run dev
```

Lascia aperto anche questo terminale.

### Passo 5: Accedi all'app

⚠️ **IMPORTANTE**: Accedi tramite ngrok, NON tramite localhost!

```
https://lesa-stripier-elena.ngrok-free.dev
```

**NON usare:** `http://localhost:8080` (questo causerà errori Mixed Content)

## 🎯 Verifica che Funzioni

1. Apri `https://lesa-stripier-elena.ngrok-free.dev`
2. Apri DevTools (F12) > Console
3. NON dovresti vedere errori `ERR_CONNECTION_REFUSED`
4. La pagina si dovrebbe caricare correttamente
5. Modifica un file Vue → la pagina si dovrebbe aggiornare automaticamente (HMR)

## 🔄 Quando Riavvii ngrok

L'URL ngrok cambia ad ogni riavvio. Usa lo script helper:

```bash
./update-ngrok-url.sh
```

Questo script ora aggiorna automaticamente anche le variabili `VITE_HMR_*`!

## 📊 Architettura Corretta

```
Browser
    ↓ accede a
https://lesa-stripier-elena.ngrok-free.dev (ngrok)
    ↓ tunnel verso
Container Apache (porta 80)
    ├─→ Richieste Laravel → PHP
    ├─→ Richieste /app/* → Reverb (porta 8080)
    └─→ Richieste /@vite/* e /resources/* → Vite (porta 5173)

Vite HMR WebSocket:
wss://lesa-stripier-elena.ngrok-free.dev:443
    ↓ tramite Apache
    ↓
Vite Dev Server (porta 5173)
```

## 🔍 Troubleshooting

### Errore: Mixed Content

**Sintomo:** Errori in console tipo "Mixed Content: The page at 'https://...' was loaded over HTTPS, but requested an insecure resource 'http://...'"

**Soluzione:** 
- Verifica che `APP_URL` usi `https://`
- Verifica che `VITE_HMR_HOST` non includa `http://` (solo il dominio)
- Accedi sempre tramite ngrok (HTTPS), mai tramite localhost (HTTP)

### Errore: ERR_CONNECTION_REFUSED su localhost:5173

**Sintomo:** Console mostra `GET http://localhost:5173/@vite/client net::ERR_CONNECTION_REFUSED`

**Causa:** Vite sta ancora usando localhost invece di ngrok

**Soluzione:**
1. Verifica che `VITE_HMR_HOST` e `VITE_HMR_CLIENT_PORT` siano nel `.env`
2. Ferma Vite (Ctrl+C)
3. Riavvia i container: `vendor/bin/sail down && vendor/bin/sail up -d ngrok`
4. Riavvia Vite: `vendor/bin/sail npm run dev`

### Vite funziona su localhost:5174 ma non su ngrok

**Sintomo:** `http://localhost:5174` mostra Vite welcome, ma ngrok non funziona

**Causa:** Stai accedendo direttamente a Vite invece che tramite Apache

**Soluzione:** Accedi sempre tramite ngrok: `https://lesa-stripier-elena.ngrok-free.dev`

### Porta 5173 vs 5174

**Docker mapping:**
- Porta interna container: 5173
- Porta esterna host: 5174 (se `VITE_PORT=5174` nel `.env`)

**Raccomandazione:** Usa `VITE_PORT=5173` per evitare confusione.

## 📝 Configurazione Completa .env

Ecco tutte le variabili necessarie:

```env
# App
APP_URL=https://lesa-stripier-elena.ngrok-free.dev
APP_PORT=8080

# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb Server
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb App
REVERB_APP_ID=123456
REVERB_APP_KEY=your-app-key-here
REVERB_APP_SECRET=your-app-secret-here

# Reverb Client
REVERB_HOST=lesa-stripier-elena.ngrok-free.dev
REVERB_PORT=443
REVERB_SCHEME=https

# Vite Reverb
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Vite HMR (IMPORTANTE!)
VITE_HMR_HOST=lesa-stripier-elena.ngrok-free.dev
VITE_HMR_CLIENT_PORT=443
VITE_PORT=5173
```

## 🎉 Risultato Finale

Quando tutto funziona correttamente:

✅ Accedi a `https://lesa-stripier-elena.ngrok-free.dev`
✅ La pagina si carica senza errori
✅ Console senza errori `ERR_CONNECTION_REFUSED`
✅ HMR funziona (modifiche ai file Vue si riflettono automaticamente)
✅ Echo si connette correttamente a Reverb
✅ I webhook FIC appaiono in tempo reale nella dashboard

---

**Aggiornamento:** 2026-01-21  
**Autore:** Fix per Vite HMR con ngrok + Laravel Sail + Docker
