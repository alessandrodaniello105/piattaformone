# FIC Synchronization Setup Guide

This guide explains how to achieve full synchronization between your Laravel app and Fatture in Cloud (FIC).

## Overview

Your app has two synchronization mechanisms:

1. **Initial Sync**: One-time bulk import of existing data from FIC API
2. **Real-time Sync**: Webhook-based updates when data changes in FIC

## Step 1: Run Initial Sync

This fetches all existing clients, suppliers, invoices, and quotes from FIC API.

### Option A: Sync All Resources (Recommended)
```bash
vendor/bin/sail artisan fic:sync all
```

### Option B: Sync Specific Resource Types
```bash
# Sync only clients
vendor/bin/sail artisan fic:sync clients

# Sync only suppliers
vendor/bin/sail artisan fic:sync suppliers

# Sync only invoices
vendor/bin/sail artisan fic:sync invoices

# Sync only quotes
vendor/bin/sail artisan fic:sync quotes
```

### Option C: Sync for Specific Account
```bash
vendor/bin/sail artisan fic:sync all --account-id=1
```

**Note**: This command dispatches jobs to the queue. Make sure queue workers are running (see Step 3).

## Step 2: Set Up Webhook Subscriptions

Webhooks enable real-time synchronization when data changes in FIC.

### 2.1 Check Existing Subscriptions

```bash
# Sync subscriptions from FIC API to local database
vendor/bin/sail artisan fic:sync-subscriptions

# Check what subscriptions exist
vendor/bin/sail artisan fic:sync-subscriptions --dry-run
```

### 2.2 Create Webhook Subscriptions

You need to create subscriptions for each event group you want to monitor:

- **Entity events** (clients, suppliers): `entity`
- **Issued documents** (invoices, quotes): `issued_documents`

#### Via API Endpoint

Create subscriptions via the API endpoint (if you have a UI for this):
```
POST /api/fic/subscriptions
```

With payload:
```json
{
  "account_id": 1,
  "event_group": "entity",
  "event_types": [
    "it.fattureincloud.webhooks.entities.clients.create",
    "it.fattureincloud.webhooks.entities.clients.update",
    "it.fattureincloud.webhooks.entities.clients.delete",
    "it.fattureincloud.webhooks.entities.suppliers.create",
    "it.fattureincloud.webhooks.entities.suppliers.update",
    "it.fattureincloud.webhooks.entities.suppliers.delete"
  ],
  "sink": "https://your-domain.com/api/webhooks/fic/1/entity"
}
```

#### Required Event Types

For **entity** group (clients & suppliers):
- `it.fattureincloud.webhooks.entities.clients.create`
- `it.fattureincloud.webhooks.entities.clients.update`
- `it.fattureincloud.webhooks.entities.clients.delete`
- `it.fattureincloud.webhooks.entities.suppliers.create`
- `it.fattureincloud.webhooks.entities.suppliers.update`
- `it.fattureincloud.webhooks.entities.suppliers.delete`

For **issued_documents** group (invoices & quotes):
- `it.fattureincloud.webhooks.issued_documents.invoices.create`
- `it.fattureincloud.webhooks.issued_documents.invoices.update`
- `it.fattureincloud.webhooks.issued_documents.invoices.delete`
- `it.fattureincloud.webhooks.issued_documents.quotes.create`
- `it.fattureincloud.webhooks.issued_documents.quotes.update`
- `it.fattureincloud.webhooks.issued_documents.quotes.delete`

### 2.3 Verify Webhook URLs

Your webhook URLs must be publicly accessible and follow this format:
```
https://your-domain.com/api/webhooks/fic/{account_id}/{event_group}
```

Example:
- `https://your-domain.com/api/webhooks/fic/1/entity`
- `https://your-domain.com/api/webhooks/fic/1/issued_documents`

## Step 3: Ensure Queue Workers Are Running

Sync jobs are processed asynchronously via queues. You **must** have queue workers running.

### Check if Queue Workers Are Running

```bash
# Check if queue worker processes are running
ps aux | grep "queue:work"

# Or check inside the container
vendor/bin/sail exec laravel.test ps aux | grep "queue:work"

# Check failed jobs (indicates workers may not be processing)
vendor/bin/sail artisan queue:failed

# Check queue size in Redis (if using Redis)
vendor/bin/sail artisan tinker
```
Then: 
```php
Illuminate\Support\Facades\Redis::llen('queues:redis');
```

### Start Queue Workers

**IMPORTANTE**: I queue workers DEVONO essere in esecuzione per processare i job. Senza workers, i job rimangono in coda Redis ma non vengono processati.

#### Con Docker Compose (Raccomandato)

Il servizio `queue-worker` è configurato nel `docker-compose.yml` e si avvia automaticamente:

```bash
# Avvia tutti i servizi (incluso il queue worker)
vendor/bin/sail up -d

# Verifica che il worker sia in esecuzione
vendor/bin/sail ps | grep queue-worker

# Vedi i log del worker
vendor/bin/sail logs queue-worker -f
```

#### Avvio Manuale (Sviluppo)

Se preferisci avviare il worker manualmente:

```bash
# Start queue worker (development) - in background
vendor/bin/sail artisan queue:work redis --verbose --tries=3 --timeout=120 &

# Oppure usa lo script helper
./start-queue-worker.sh &

# Per vedere i log in tempo reale
vendor/bin/sail artisan queue:work redis --verbose --tries=3 --timeout=120

# Verifica che sia in esecuzione
ps aux | grep "queue:work"
```

**Nota**: 
- Il comando `composer run dev` avvia automaticamente `queue:listen`
- Con Sail, il servizio `queue-worker` nel docker-compose si avvia automaticamente
- Per produzione, usa Supervisor o systemd (vedi sotto)

### For Production

In production, use a process manager like Supervisor or systemd to keep queue workers running.

Example Supervisor config:
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/worker.log
stopwaitsecs=3600
```

## Step 4: Verify Synchronization

### 4.1 Check Synced Data Counts

```bash
# Use Tinker to check counts
vendor/bin/sail artisan tinker
```

Then in Tinker:
```php
$account = App\Models\FicAccount::first();
$account->clients()->count();
$account->suppliers()->count();
$account->invoices()->count();
$account->quotes()->count();
```

### 4.2 Check Queue Status

```bash
# Check failed jobs
vendor/bin/sail artisan queue:failed

# Check queue size (if using Redis)
vendor/bin/sail artisan tinker
```

In Tinker:
```php
use Illuminate\Support\Facades\Redis;
Redis::llen('queues:redis');
```

### 4.3 Check Webhook Events

```bash
# Check recent webhook events
vendor/bin/sail artisan tinker
```

In Tinker:
```php
App\Models\FicEvent::latest()->take(10)->get();
```

### 4.4 Check Logs

```bash
# Check Laravel logs for sync errors
tail -f storage/logs/laravel.log | grep "FIC Sync"
```

## Step 5: Monitor Ongoing Sync

### Check Last Sync Time

```bash
vendor/bin/sail artisan tinker
```

In Tinker:
```php
$account = App\Models\FicAccount::first();
$account->last_sync_at;
```

### Monitor Webhook Activity

Webhooks should automatically sync changes. Check logs:
```bash
tail -f storage/logs/laravel.log | grep "FIC Webhook"
```

## Troubleshooting

### Issue: No data synced

1. **Check FIC Account**: Ensure you have a connected FIC account with valid access token
   ```bash
   vendor/bin/sail artisan tinker
   ```
   ```php
   $account = App\Models\FicAccount::first();
   $account->access_token; // Should not be null
   ```

2. **Check Queue Workers**: Ensure queue workers are running
   ```bash
   ps aux | grep "queue:work"
   ```

3. **Check Failed Jobs**: Look for failed sync jobs
   ```bash
   vendor/bin/sail artisan queue:failed
   ```

### Issue: Webhooks not working

1. **Verify Subscriptions**: Check if subscriptions exist and are verified
   ```bash
   vendor/bin/sail artisan fic:sync-subscriptions
   ```

2. **Check Webhook URL**: Ensure URL is publicly accessible
   ```bash
   curl https://your-domain.com/api/webhooks/fic/1/entity
   ```

3. **Check Logs**: Look for webhook errors
   ```bash
   tail -f storage/logs/laravel.log | grep "FIC Webhook"
   ```

### Issue: Queue jobs not processing

1. **Check Queue Connection**: Ensure Redis is running
   ```bash
   vendor/bin/sail redis-cli ping
   ```

2. **Check Queue Configuration**: Verify `.env` has correct queue settings
   ```
   QUEUE_CONNECTION=redis
   ```

3. **Restart Queue Workers**: Sometimes workers need restart
   ```bash
   # Stop existing workers
   pkill -f "queue:work"
   
   # Start new workers
   vendor/bin/sail artisan queue:work redis
   ```

## Summary Checklist

- [ ] Run initial sync: `vendor/bin/sail artisan fic:sync all`
- [ ] Verify queue workers are running
- [ ] Create webhook subscriptions for `entity` group
- [ ] Create webhook subscriptions for `issued_documents` group
- [ ] Verify subscriptions are active: `vendor/bin/sail artisan fic:sync-subscriptions`
- [ ] Check synced data counts in database
- [ ] Monitor logs for errors
- [ ] Test webhook by creating/updating a resource in FIC

## Next Steps

After completing the setup:

1. **Schedule Periodic Syncs** (optional): Add a scheduled task to run `fic:sync` periodically
2. **Monitor Sync Health**: Set up alerts for failed sync jobs
3. **Review Sync Performance**: Monitor queue processing times
