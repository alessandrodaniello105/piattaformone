# Configurazione Laravel Reverb per Webhook in Tempo Reale

Questo documento descrive come configurare Laravel Reverb per visualizzare i webhook di Fatture in Cloud in tempo reale.

## Installazione Completata

✅ Laravel Reverb installato
✅ Laravel Echo e pusher-js installati
✅ Evento `WebhookReceived` creato
✅ Controller webhook configurato per emettere eventi broadcast
✅ Dashboard Vue configurata per ascoltare eventi in tempo reale
✅ Apache configurato con reverse proxy per WebSocket su `/app/`

## ⚠️ PROBLEMA COMUNE: WebSocket non si connette

**Sintomo**: Errore in console: `WebSocket connection to 'wss://your-ngrok-url/app/...' failed`

**Causa**: Le variabili d'ambiente `VITE_REVERB_*` non sono configurate correttamente nel file `.env`

**Soluzione rapida**:
1. Aggiungi le variabili al file `.env` (vedi sezione "Configurazione Variabili d'Ambiente" sotto)
2. Riavvia Vite: `vendor/bin/sail npm run dev`
3. Riavvia Reverb: `vendor/bin/sail artisan reverb:start`
4. Ricarica la pagina del browser

## Configurazione Variabili d'Ambiente

### Step 1: Aggiungi le variabili al tuo file `.env`

**Per ngrok (HTTPS):**

```env
# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb App Credentials
REVERB_APP_ID=123456
REVERB_APP_KEY=your-app-key-here
REVERB_APP_SECRET=your-app-secret-here

# Reverb Server Configuration (interno Docker)
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb Client Configuration (come si connette dall'esterno)
# IMPORTANTE: usa il tuo URL ngrok qui!
REVERB_HOST=lesa-stripier-elena.ngrok-free.dev
REVERB_PORT=443
REVERB_SCHEME=https

# Frontend Configuration (Vite)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

**Per sviluppo locale (HTTP senza ngrok):**

```env
# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb App Credentials
REVERB_APP_ID=123456
REVERB_APP_KEY=your-app-key-here
REVERB_APP_SECRET=your-app-secret-here

# Reverb Server Configuration
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb Client Configuration
REVERB_HOST=localhost
REVERB_PORT=80
REVERB_SCHEME=http

# Frontend Configuration (Vite)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Step 2: Riavvia i servizi

**IMPORTANTE**: Dopo aver modificato il file `.env`, devi riavviare:

1. **Vite** (per caricare le nuove variabili VITE_*):
   ```bash
   # Ferma Vite (Ctrl+C nel terminale dove è in esecuzione)
   # Poi riavvialo:
   vendor/bin/sail npm run dev
   ```

2. **Reverb** (ferma con Ctrl+C e riavvia):
   ```bash
   vendor/bin/sail artisan reverb:start
   ```

3. **Opzionale - Container Docker** (se le variabili non vengono caricate):
   ```bash
   vendor/bin/sail down
   vendor/bin/sail up -d
   ```

### Generare le Chiavi Reverb

**Opzione 1: Automatica** (raccomandato)
```bash
vendor/bin/sail artisan reverb:install
```

Questo comando ti chiederà se vuoi installare Reverb e genererà automaticamente le chiavi nel file `.env`.

**Opzione 2: Manuale**

Puoi generare chiavi casuali con questi comandi:

```bash
# REVERB_APP_ID (numero casuale)
echo $((RANDOM * RANDOM))

# REVERB_APP_KEY (stringa alfanumerica)
openssl rand -base64 32 | tr -d "=+/" | cut -c1-32

# REVERB_APP_SECRET (stringa base64)
openssl rand -base64 32
```

### Script Helper Disponibili

#### 1. Script di Controllo Configurazione

```bash
./check-reverb.sh
```

Verifica:
- Presenza di tutte le variabili d'ambiente richieste
- Stato del server Reverb
- Configurazione broadcasting
- Valori attuali delle variabili

#### 2. Script di Aggiornamento URL ngrok

```bash
./update-ngrok-url.sh
```

**IMPORTANTE**: Con l'account ngrok gratuito, l'URL cambia ad ogni riavvio.

Questo script:
- Recupera automaticamente l'URL ngrok corrente
- Aggiorna tutte le variabili REVERB_* e VITE_REVERB_* nel `.env`
- Crea un backup del `.env` prima di modificarlo

Esegui questo script ogni volta che riavvii ngrok!

## Avviare il Server Reverb

Per avviare il server WebSocket Reverb, esegui:

```bash
php artisan reverb:start
```

Per lo sviluppo, puoi avviarlo in background o in un terminale separato.

### Con Docker/ngrok

Se stai usando ngrok per esporre l'applicazione, assicurati che:

1. Il server Reverb sia accessibile tramite la stessa URL base
2. Le variabili `VITE_REVERB_HOST` e `VITE_REVERB_PORT` puntino all'URL ngrok
3. `REVERB_SCHEME` sia impostato su `https` se usi HTTPS

Esempio con ngrok:

```env
REVERB_HOST=your-ngrok-url.ngrok.io
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_HOST=your-ngrok-url.ngrok.io
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Testare la Configurazione

1. **Avvia il server Reverb:**
   ```bash
   php artisan reverb:start
   ```

2. **Avvia l'applicazione Laravel:**
   ```bash
   php artisan serve
   ```

3. **Compila gli asset frontend:**
   ```bash
   npm run dev
   # oppure
   npm run build
   ```

4. **Apri la Dashboard:**
   - Vai su `/dashboard`
   - Dovresti vedere lo stato di connessione Echo (pallino verde = connesso)
   - Quando arriva un webhook da Fatture in Cloud, apparirà nella sezione "Webhook in Tempo Reale"

## Come Funziona

1. **Webhook Ricevuto:** Quando Fatture in Cloud invia un webhook al tuo endpoint `/api/webhooks/fic/{account_id}/{group}`, il `FicWebhookController`:
   - Valida il webhook
   - Accoda il job `ProcessFicWebhook` per l'elaborazione asincrona
   - Emette l'evento `WebhookReceived` che viene broadcastato tramite Reverb

2. **Broadcast Event:** L'evento `WebhookReceived` viene inviato ai canali:
   - `webhooks` (canale pubblico per tutti i webhook)
   - `webhooks.account.{account_id}` (canale specifico per account)

3. **Frontend:** Il componente Dashboard Vue:
   - Si connette a Reverb tramite Echo
   - Ascolta il canale `webhooks`
   - Visualizza i webhook in tempo reale quando arrivano

## Troubleshooting

### Echo non si connette

1. **Verifica che il server Reverb sia in esecuzione:**
   ```bash
   vendor/bin/sail artisan reverb:start
   ```

2. **Controlla le variabili d'ambiente `VITE_REVERB_*` nel browser (DevTools > Console):**
   ```javascript
   console.log('VITE_REVERB_APP_KEY:', import.meta.env.VITE_REVERB_APP_KEY);
   console.log('VITE_REVERB_HOST:', import.meta.env.VITE_REVERB_HOST);
   console.log('VITE_REVERB_PORT:', import.meta.env.VITE_REVERB_PORT);
   console.log('VITE_REVERB_SCHEME:', import.meta.env.VITE_REVERB_SCHEME);
   ```
   
   **IMPORTANTE**: Se queste variabili sono `undefined`, significa che:
   - Non hai impostato le variabili nel file `.env`
   - Oppure non hai riavviato Vite dopo averle aggiunte

   **SOLUZIONE**: 
   1. Copia le variabili da `.env.reverb.example` nel tuo `.env`
   2. Modifica `REVERB_HOST` con il tuo URL ngrok
   3. Ferma e riavvia Vite: `vendor/bin/sail npm run dev`

3. **Verifica che `BROADCAST_CONNECTION=reverb` sia impostato nel `.env`**
   ```bash
   vendor/bin/sail exec laravel.test php artisan config:show broadcasting
   ```

4. **Controlla i log del server Reverb per errori** nel terminale dove è in esecuzione `reverb:start`

### Webhook non appaiono in tempo reale

1. Verifica che l'evento venga emesso nel controller (controlla i log Laravel)
2. Verifica che il broadcasting sia abilitato (`BROADCAST_CONNECTION=reverb`)
3. Controlla la console del browser per errori JavaScript
4. Verifica che il canale `webhooks` sia pubblico (non richiede autenticazione)

### Problemi con ngrok

Se usi ngrok, assicurati che:
- Il tunnel ngrok punti alla porta corretta (porta 80 di Apache, non 8080 di Reverb)
- Apache è configurato per fare proxy delle richieste `/app/` a Reverb su porta 8080 (già configurato)
- Le variabili `VITE_REVERB_HOST` usino l'URL ngrok (es: `lesa-stripier-elena.ngrok-free.dev`)
- `REVERB_SCHEME` sia `https`
- `REVERB_PORT` sia `443`

**Errore comune**: `WebSocket connection to 'wss://your-ngrok-url.ngrok-free.dev/app/...' failed`

**Causa**: Le variabili `VITE_REVERB_*` non sono impostate o Vite non è stato riavviato dopo averle aggiunte.

**Soluzione**:
1. Verifica che le variabili siano nel `.env`:
   ```bash
   vendor/bin/sail exec laravel.test cat .env | grep VITE_REVERB
   ```

2. Verifica che Vite le abbia caricate (nel browser, Console DevTools):
   ```javascript
   console.log(import.meta.env.VITE_REVERB_HOST);
   ```
   Se risulta `undefined`, riavvia Vite.

3. Verifica che Reverb sia in ascolto:
   ```bash
   vendor/bin/sail exec laravel.test curl -I http://localhost:8080/
   ```
   Dovrebbe rispondere con status 200.

4. Verifica che Apache faccia correttamente il proxy (dal tuo browser):
   - Apri DevTools > Network
   - Filtra per "WS" (WebSocket)
   - Ricarica la pagina
   - Dovresti vedere una connessione WebSocket a `/app/...`
   - Se la connessione fallisce con status 101 (Switching Protocols) fallito, c'è un problema con il proxy Apache

## Note

- Il server Reverb deve essere in esecuzione per ricevere i webhook in tempo reale
- I webhook vengono comunque processati anche se Reverb non è attivo (tramite job queue)
- La visualizzazione in tempo reale è solo per monitoraggio/debug
- In produzione, considera di usare un processo manager come Supervisor per Reverb
