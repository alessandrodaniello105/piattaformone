Setup iniziale
[x] Configurazione Docker (Sail + Apache + PostgreSQL + Redis)
[x] File Dockerfile Apache personalizzato
[x] Configurazione Apache virtual host
[x] File .dockerignore
[x] Verificare che il Dockerfile si chiami Dockerfile (non DockerFile)
[x] Aggiornare docker-compose.yml per usare Dockerfile personalizzato
[x] Testare avvio container: ./vendor/bin/sail up -d
Configurazione Laravel
[X] Configurare PostgreSQL nel .env
[X] Configurare Redis nel .env
[X] Verificare che config/database.php supporti PostgreSQL
[X] Verificare che config/cache.php usi Redis
[X] Verificare che config/queue.php usi Redis
Integrazione Fatture in Cloud - Step 1
[x] Installare PHP SDK Fatture in Cloud: composer require fattureincloud/api-sdk-php
[x] Creare config file: config/fattureincloud.php
[x] Creare Service Provider per FIC (opzionale, per dependency injection)
[x] Configurare OAuth2 Authorization Code Flow (redirect, callback, token storage in Redis)
[x] Creare migration per tabella fic_events (salvare eventi ricevuti)
[x] Creare migration per tabella fic_accounts (per multi-tenant)
[x] Creare Controller: FicWebhookController per gestire webhook multi-tenant
[x] Creare Route: POST /api/webhooks/fic/{account_id}/{group} (con rate limiting)
[x] Implementare verifica subscription (GET con challenge)
[x] Implementare verifica JWT per notifiche POST
[x] Implementare CloudEvents format (Binary e Structured mode)
[x] Implementare queue jobs per processare webhook in background (ProcessFicWebhook)
[x] Implementare broadcasting real-time via Reverb (WebhookReceived event)
[ ] Testare con account trial FIC
Testing
[ ] Test unitari per verifica webhook
[ ] Test integrazione con FIC API (mock)
[ ] Test end-to-end: creare entity in FIC → ricevere webhook
5. Configurazioni aggiuntive
A. Sicurezza
Rate limiting per endpoint webhook (forse non è necessario, vedremo in seguito)
Middleware per validazione IP (se FIC fornisce IP whitelist)
Logging delle richieste webhook per audit
Encryption delle credenziali in database (Laravel Encryption)
B. Performance e scalabilità
Queue jobs per processare webhook in background
Cache per dati FIC (evitare troppe chiamate API)
Database indexing su tabelle webhook
Health check endpoint per monitoring
C. Multi-tenancy (futuro)
Package Laravel per multi-tenancy (es. stancl/tenancy)
Isolamento dati per cliente
Gestione credenziali multiple FIC
D. Monitoring e debugging
Logging strutturato (Monolog)
Error tracking (Sentry opzionale)
Dashboard per vedere webhook ricevuti
[x] Command Artisan per testare connessione FIC (fic:test)
E. Sviluppo
.env.example completo con tutte le variabili
Docker compose per ambiente test
Seeders per dati di test
Postman collection per testare API
