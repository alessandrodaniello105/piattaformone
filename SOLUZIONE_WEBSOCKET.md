# 🔧 SOLUZIONE: WebSocket non si connette con ngrok

## 📋 Problema

Errore in console:
```
WebSocket connection to 'wss://lesa-stripier-elena.ngrok-free.dev/app/...' failed
```

## ✅ Soluzione Completa

### Passo 1: Verifica il file .env

Le variabili sono già presenti nel tuo `.env`. Ora devi solo riavviare i servizi per caricarle.

### Passo 2: Riavvia i container Docker

```bash
cd /home/kanym/projects/piattaformone/piattaformone

# Ferma i container
vendor/bin/sail down

# Riavviali
vendor/bin/sail up -d
```

Questo ricaricherà tutte le variabili d'ambiente dal file `.env`.

### Passo 3: Avvia Reverb in un terminale separato

In un **nuovo terminale**, esegui:

```bash
cd /home/kanym/projects/piattaformone/piattaformone
vendor/bin/sail artisan reverb:start
```

**IMPORTANTE**: 
- Lascia questo terminale aperto
- Reverb deve rimanere in esecuzione
- Vedrai: `INFO Starting server on 0.0.0.0:8080 (lesa-stripier-elena.ngrok-free.dev)`

### Passo 4: Avvia Vite in un altro terminale

In un **altro nuovo terminale**, esegui:

```bash
cd /home/kanym/projects/piattaformone/piattaformone
vendor/bin/sail npm run dev
```

**IMPORTANTE**: 
- Lascia anche questo terminale aperto
- Vite deve rimanere in esecuzione per il Hot Module Replacement
- Vedrai la build di Vite completarsi

### Passo 5: Verifica nel browser

1. Apri il browser e vai alla dashboard
2. Apri DevTools (F12) > Console
3. Dovresti vedere questi log:
   ```javascript
   Echo configuration: {...}
   Reverb URL: https://lesa-stripier-elena.ngrok-free.dev:443
   Echo connecting...
   Echo connected!
   ```

4. Se vedi "Echo connected!", la connessione funziona! 🎉

### Passo 6: Testa con un webhook

Invia un webhook di test da Fatture in Cloud e dovresti vedere:
1. Nel terminale di Reverb: log della connessione WebSocket
2. Nella console del browser: `Webhook received: {...}`
3. Nella dashboard: la notifica appare in tempo reale

## 🔍 Troubleshooting

### Se import.meta.env.VITE_REVERB_HOST è undefined

Nel browser (DevTools > Console):
```javascript
console.log('VITE_REVERB_HOST:', import.meta.env.VITE_REVERB_HOST);
```

Se risulta `undefined`:
1. Ferma Vite (Ctrl+C)
2. Verifica che le variabili `VITE_REVERB_*` siano nel `.env`
3. Riavvia Vite: `vendor/bin/sail npm run dev`

### Se Vite HMR non funziona (pagina bianca con npm run dev)

**Sintomo**: 
- Con `npm run build` → tutto funziona ✅
- Con `npm run dev` → pagina bianca, errore `ERR_CONNECTION_REFUSED` su `localhost:5173` ❌

**Causa**: 
Vite HMR cerca di caricare asset da `http://localhost:5173` ma:
1. Stai usando ngrok con HTTPS (mixed content error)
2. La porta potrebbe essere mappata diversamente (5174 invece di 5173)

**Soluzione**:
Aggiungi queste variabili al `.env`:
```env
APP_URL=https://lesa-stripier-elena.ngrok-free.dev
VITE_HMR_HOST=lesa-stripier-elena.ngrok-free.dev
VITE_HMR_CLIENT_PORT=443
VITE_PORT=5173
```

Poi:
1. `vendor/bin/sail down && vendor/bin/sail up -d ngrok`
2. `vendor/bin/sail npm run dev`
3. Accedi tramite ngrok: `https://lesa-stripier-elena.ngrok-free.dev`

### Se la connessione WebSocket fallisce ancora

1. Verifica che ngrok sia in esecuzione e punti alla porta corretta
2. Controlla che l'URL ngrok nel `.env` sia aggiornato (ngrok cambia URL ad ogni riavvio con account free)
3. Verifica che Apache sia configurato correttamente (è già stato fatto)

### Verifica configurazione con lo script helper

```bash
./check-reverb.sh
```

Questo script ti dirà esattamente cosa manca.

## 📝 Riepilogo Modifiche Effettuate

1. ✅ Aggiornato `resources/js/bootstrap.js` con configurazione corretta per Echo
2. ✅ Creato script `check-reverb.sh` per verificare la configurazione
3. ✅ Aggiornato `REVERB_SETUP.md` con troubleshooting completo
4. ✅ Il reverse proxy Apache è già configurato in `docker/apache/000-default.conf`

## 🎯 Cosa Aspettarsi

Quando tutto funziona correttamente:

1. **Terminale Reverb**: Vedrai log delle connessioni WebSocket
   ```
   INFO Starting server on 0.0.0.0:8080 (lesa-stripier-elena.ngrok-free.dev)
   ```

2. **Console Browser**: Vedrai conferma della connessione
   ```
   Echo connected!
   ```

3. **Dashboard**: Vedrai un pallino verde che indica "Echo connesso"

4. **Webhook in tempo reale**: Quando arriva un webhook da FIC, apparirà immediatamente nella dashboard

## 🚀 Quick Start (TL;DR)

```bash
# 0. (Opzionale) Se hai riavviato ngrok, aggiorna l'URL:
./update-ngrok-url.sh

# 1. Riavvia container
vendor/bin/sail down && vendor/bin/sail up -d

# 2. In un terminale: avvia Reverb
vendor/bin/sail artisan reverb:start

# 3. In un altro terminale: avvia Vite
vendor/bin/sail npm run dev

# 4. Apri browser > Dashboard > Verifica pallino verde
```

## 🔄 Script Helper Disponibili

### `./update-ngrok-url.sh`
Aggiorna automaticamente l'URL ngrok nel file `.env`. 

**Quando usarlo**: Ogni volta che riavvii ngrok (l'account free cambia URL ad ogni avvio).

**Come funziona**: 
- Recupera automaticamente l'URL ngrok dall'API locale (http://localhost:4040)
- Aggiorna tutte le variabili REVERB_* e VITE_REVERB_* nel `.env`
- Crea un backup del `.env` prima di modificarlo

```bash
./update-ngrok-url.sh
```

### `./check-reverb.sh`
Verifica la configurazione Reverb e mostra lo stato attuale.

**Quando usarlo**: Per diagnosticare problemi di connessione.

```bash
./check-reverb.sh
```

## 📞 Se Hai Ancora Problemi

Controlla:
1. L'URL ngrok nel `.env` corrisponde all'URL attuale di ngrok
2. Reverb è in esecuzione (terminale deve essere aperto)
3. Vite è in esecuzione (terminale deve essere aperto)
4. Le variabili VITE_* sono caricate nel browser (console.log)

Se nulla funziona, rileggi `REVERB_SETUP.md` per la guida completa.
