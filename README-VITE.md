# Configurazione Vite con Docker e ngrok

## Panoramica

Questa applicazione usa Vite per il dev server frontend con la seguente configurazione:

- **Vite** gira sulla porta **5174** all'interno del container Docker
- **Docker** mappa la porta interna 5174 → porta esterna **5173** (accessibile dall'host)
- **Il browser** carica gli asset direttamente da `localhost:5173` (porta esterna)
- **ngrok** espone il server web principale, ma Vite è accessibile direttamente

## Flusso di Funzionamento

```
┌─────────────────────────────────────────────────────────────┐
│ Container Docker (laravel.test)                              │
│                                                              │
│  ┌──────────────┐         ┌──────────────┐                 │
│  │   Apache     │         │    Vite      │                 │
│  │   (porta 80) │         │  (porta 5174)│                 │
│  └──────┬───────┘         └──────┬───────┘                 │
│         │                        │                          │
└─────────┼────────────────────────┼──────────────────────────┘
          │                        │
          │ mappata a              │ mappata a
          │ porta 80               │ porta 5173
          │ (host)                 │ (host)
          │                        │
          ▼                        ▼
    ┌─────────┐            ┌──────────────┐
    │  ngrok  │            │   Browser    │
    │ (HTTPS) │            │  carica da   │
    └─────────┘            │ localhost:5173│
                           └──────────────┘
```

## Configurazione Chiave

### 1. `vite.config.js`

```javascript
server: {
    host: true,
    port: 5174,  // Porta INTERNA del container
    hmr: {
        clientPort: 5173,  // Porta ESTERNA per il browser
        port: 5173,
    },
    origin: 'http://localhost:5173',
}
```

**Importante**: Questa configurazione fa sì che Vite scriva automaticamente `http://localhost:5173` nel file `public/hot`, che è l'URL corretto per il browser.

### 2. `docker-compose.yml`

```yaml
ports:
  - '${VITE_PORT:-5173}:5174'
```

Mappa la porta interna 5174 alla porta esterna 5173.

### 3. `docker/apache/000-default.conf`

```apache
ServerAlias *
```

Permette ad Apache di accettare richieste da qualsiasi host (incluso ngrok).

**Nota**: Non serve proxy Apache per Vite. Il browser carica direttamente da `localhost:5173`.

## Come Funziona

1. **Avvio Vite**: `vendor/bin/sail npm run dev`
   - Vite si avvia sulla porta 5174 all'interno del container
   - Scrive automaticamente `http://localhost:5173` in `public/hot`

2. **Laravel legge `public/hot`**:
   - Genera i tag `<script src="http://localhost:5173/resources/js/app.js">`
   - Genera i tag `<link href="http://localhost:5173/resources/css/app.css">`

3. **Il browser**:
   - Accede all'app tramite ngrok (es. `https://xxx.ngrok-free.dev`)
   - Carica gli asset Vite direttamente da `localhost:5173` (porta Docker esposta)
   - Si connette al WebSocket HMR su `localhost:5173`

## Risoluzione Problemi

### Problema: Asset non caricano (ERR_CONNECTION_REFUSED)

**Causa**: Vite non è in esecuzione o il file `public/hot` contiene la porta sbagliata.

**Soluzione**:
1. Verifica che Vite sia in esecuzione: `vendor/bin/sail npm run dev`
2. Controlla `public/hot`: deve contenere `http://localhost:5173`
3. Se contiene `5174`, ferma Vite e riavvialo

### Problema: HMR (Hot Module Replacement) non funziona

**Causa**: Configurazione HMR non corretta in `vite.config.js`.

**Soluzione**: Verifica che `vite.config.js` abbia:
```javascript
hmr: {
    clientPort: 5173,
    port: 5173,
}
```

### Problema: Dopo riavvio container Vite non parte

**Causa**: Vite non si riavvia automaticamente quando riavvii `laravel.test`.

**Soluzione**: Riavvia manualmente Vite dopo ogni restart del container:
```bash
vendor/bin/sail restart laravel.test
vendor/bin/sail npm run dev
```

## Script Utili

### Aggiornare URL ngrok

```bash
./update-ngrok-url.sh
```

Aggiorna automaticamente le variabili d'ambiente `.env` con l'URL ngrok corrente.

## Checklist Setup

- [ ] Docker in esecuzione: `vendor/bin/sail up -d`
- [ ] Vite in esecuzione: `vendor/bin/sail npm run dev`
- [ ] File `public/hot` contiene `http://localhost:5173`
- [ ] ngrok in esecuzione: `ngrok http 80`
- [ ] Variabili `.env` aggiornate con URL ngrok: `./update-ngrok-url.sh`
- [ ] Laravel Reverb in esecuzione: `vendor/bin/sail artisan reverb:start`

## Variabili d'Ambiente Importanti

```env
APP_URL=https://xxx.ngrok-free.dev
VITE_APP_URL=https://xxx.ngrok-free.dev
VITE_HMR_HOST=localhost
VITE_HMR_CLIENT_PORT=5173
VITE_REVERB_HOST=xxx.ngrok-free.dev
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Note per il Deployment

Quando fai il build per produzione (`npm run build`):
- Vite genera i file in `public/build/`
- Laravel usa questi file statici invece del dev server
- Il file `public/hot` viene eliminato
- Non serve che Vite sia in esecuzione
