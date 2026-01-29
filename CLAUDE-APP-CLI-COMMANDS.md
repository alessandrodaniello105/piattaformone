# CLAUDE-APP-CLI-COMMANDS.md

### Testing

```bash
# Run all tests
vendor/bin/sail artisan test --compact

# Run specific test file
vendor/bin/sail artisan test --compact tests/Feature/FicSyncTest.php

# Run tests matching pattern
vendor/bin/sail artisan test --compact --filter=testWebhookProcessing
```

### Code Quality

```bash
# Format code (ALWAYS run before commits)
vendor/bin/sail bin pint --dirty

# Test formatting without changes
vendor/bin/sail bin pint --test
```

### FIC Artisan Commands

```bash
# Sync resources from FIC API
vendor/bin/sail artisan fic:sync [resource]     # resource: clients, suppliers, invoices, quotes, all
vendor/bin/sail artisan fic:sync clients --account-id=123

# OAuth debugging
vendor/bin/sail artisan oauth:logs --level=error --since="1 hour ago"

# Webhook subscriptions
vendor/bin/sail artisan fic:create-subscription {account_id} {event_type}
vendor/bin/sail artisan fic:subscriptions:list
vendor/bin/sail artisan fic:subscriptions:refresh    # Renew expiring subscriptions
vendor/bin/sail artisan fic:subscriptions:sync       # Sync FIC → local DB

# Account management
vendor/bin/sail artisan fic:accounts:list

# Webhook diagnostics
vendor/bin/sail artisan fic:webhook:diagnose

# List all FIC events
vendor/bin/sail artisan fic:events:list --status=pending
```

### Database

```bash
vendor/bin/sail artisan migrate
vendor/bin/sail artisan migrate:fresh --seed
vendor/bin/sail artisan db:seed
```

**Queue Worker:**
```bash
vendor/bin/sail artisan queue:listen --tries=1
```