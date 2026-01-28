# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 12 application with **multi-tenant Fatture in Cloud (FIC) integration**. Uses Jetstream teams where each team can connect their own FIC account via OAuth, sync invoicing data (clients, suppliers, quotes, invoices), receive real-time webhook updates, and generate documents from DOCX templates using PHPWord.

**Tech Stack:** Laravel 12, Inertia.js + Vue 3, Reverb (WebSockets), PostgreSQL, Redis, Docker Sail

## Common Commands

### Development Environment

```bash
# Standard workflow (recommended)
sail up -d                               # Start Docker containers (Laravel, PostgreSQL, Redis)
sail up -d ngrok                         # Start ngrok tunnel (for webhooks/Reverb)
sail npm run dev                         # Start Vite dev server (hot reload)

# Queue worker (run in separate terminal if needed)
sail artisan queue:listen --tries=1

# Open application in browser
sail open

# Alternative: run all services in parallel (without ngrok)
vendor/bin/sail composer run dev         # Runs: server + queue + pail + vite
```

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

## Architecture

### Multi-Tenant Model

**User → Team → FicAccount → FIC Resources**

- **Team** (Jetstream): Stores per-team OAuth credentials (`fic_client_id`, `fic_client_secret`, `fic_scopes`)
- **FicAccount**: OAuth tokens, company info, belongs to one Team
- **FIC Resources** (FicClient, FicSupplier, FicQuote, FicInvoice): Belong to FicAccount

Each team has its own FIC OAuth app. Credentials fall back to `.env` if not configured per-team.

### OAuth Flow (Per-Team)

**Route:** `/api/fic/oauth/redirect`

1. `FattureInCloudOAuthController::redirect()` uses team's OAuth credentials (or `.env` fallback)
2. Generates CSRF state token stored in Redis (10 min TTL) with user/team context
3. Redirects to FIC authorization page
4. **Callback:** `/api/fic/oauth/callback` exchanges code for tokens
5. `FicOAuthCompanySelector` enforces same `company_id` if team has existing FicAccount
6. Creates/updates `FicAccount` with encrypted tokens
7. Tokens stored in `fic_accounts` table with `tenant_id` (team FK)

**Security:** State-based CSRF protection, encrypted token storage, team-company uniqueness validation

### Data Model: Raw JSON Storage

All FIC entity models (FicClient, FicSupplier, FicQuote, FicInvoice) store:
- **Normalized columns** for queries: `name`, `code`, `number`, `status`, `total_gross`, `fic_*_id`
- **Complete API response** in `raw` JSONB column for flexibility
- Method: `getRawField($field, $default)` for accessing non-normalized data
- Example: `$invoice->getRawField('entity.name')` or `$client->getRawField('tax_code')`

This pattern enables document generation without schema changes.

### Services

**FicApiService** (`app/Services/FicApiService.php`)
- Wrapper around FIC PHP SDK with HTTP fallbacks
- Takes `FicAccount` instance, uses decrypted `access_token`
- Methods: `fetchClientById()`, `fetchSuppliersList()`, `fetchQuotesList()`, `createOrRenewSubscriptionForEventType()`
- Error handling: 429 (rate limit) logs retry-after, 401 marks account as `needs_refresh`

**FicConnectionService** (`app/Services/FicConnectionService.php`)
- Manages connection status with 5-minute Redis cache
- `checkConnectionStatus($user)`: Returns connection details
- `getActiveFicAccount($user)`: Get account for current team
- Cache key includes both `user_id` and `current_team_id`

**FicCacheService** (`app/Services/FicCacheService.php`)
- Redis-based caching for FIC data (10-minute TTL)
- Keys: `fic:clients:team:{teamId}:page:{page}:perpage:{perPage}`
- `invalidate($type, $teamId)`: Clear specific resource cache
- `invalidateAll($teamId)`: Clear all FIC cache for team

**DocxVariableReplacer** (`app/Services/DocxVariableReplacer.php`)
- PHPWord-based document generation
- `replaceVariables($templatePath, $data)`: Replace `${variable}` in DOCX templates
- `extractVariables($templatePath)`: Parse template for placeholders
- Supports nested data: `invoice.number`, `client.raw.email`, `current_date`

### Webhook Processing Flow

**POST to `/api/webhooks/fic/{account_id}/{group}`**

1. **FicWebhookController** validates CloudEvents format (Binary or Structured mode)
2. JWT signature verification using ES256 (ECDSA P-256 + SHA-256)
3. Creates `FicEvent` records (status: `pending`)
4. Queues `ProcessFicWebhook` job
5. Broadcasts `WebhookReceived` event via Reverb
6. Returns `202 Accepted`

**Queue Processing:**

1. `ProcessFicWebhook` job processes event, extracts resource mapping
2. Updates `FicEvent` status to `processed`
3. Dispatches `SyncFicResourceJob` for each ID
4. `SyncFicResourceJob` fetches from FIC API, upserts to DB
5. Broadcasts `ResourceSynced` event
6. Invalidates cache

**Retry Logic:**
- `ProcessFicWebhook`: 3 retries, 60s backoff
- `SyncFicResourceJob`: 3 retries, exponential backoff (60s, 120s, 240s)

### Broadcasting (Reverb)

**Events:**
- `WebhookReceived`: Channel `webhooks.account.{accountId}` when webhook arrives
- `ResourceSynced`: Channel `sync.account.{accountId}` after successful sync

**Configuration:**
- Frontend: `REVERB_HOST` = ngrok tunnel (HTTPS), `REVERB_PORT=443`, `REVERB_SCHEME=https`
- Queue workers: `REVERB_BROADCAST_HOST=laravel.test`, `REVERB_BROADCAST_PORT=6001`, `REVERB_BROADCAST_SCHEME=http`

**Usage in Vue:**
```javascript
Echo.channel(`sync.account.${accountId}`)
  .listen('.resource.synced', (event) => {
    // Update UI with event.data
  });
```

### Queue System

**Connection:** Redis (configured in `.env`)

**Jobs:**
- `ProcessFicWebhook`: Process incoming webhook events
- `SyncFicResourceJob`: Fetch and sync individual FIC resources

**Queue Worker:**
```bash
vendor/bin/sail artisan queue:listen --tries=1
```

## Key Models and Relationships

**Team** (`app/Models/Team.php`)
- Jetstream team model
- Stores per-team FIC OAuth credentials: `fic_client_id`, `fic_client_secret` (encrypted), `fic_scopes` (JSON)
- `hasFicCredentials()`: Check if team has OAuth configured
- `getFicScopes()`: Get scopes array

**FicAccount** (`app/Models/FicAccount.php`)
- `belongsTo` Team (`tenant_id`)
- Encrypted `access_token`, `refresh_token`
- `company_id`, `company_name`, `company_email` from FIC
- Status: `active`, `needs_refresh`, `revoked`, `suspended`, `disconnected`
- `isTokenExpired()`: Check if token expired
- `needsReauth()`: Check if re-authorization needed
- Scopes: `forTeam($teamId)`, `active()`, `disconnected()`

**FicClient, FicSupplier, FicQuote, FicInvoice**
- `belongsTo` FicAccount
- Normalized columns + `raw` JSONB for complete API response
- Composite unique key: `fic_account_id` + `fic_*_id`

**FicSubscription** (`app/Models/FicSubscription.php`)
- `belongsTo` FicAccount
- `fic_subscription_id`: FIC's subscription ID
- `event_group`: routing key (entity, issued_documents, etc.)
- `webhook_secret`: encrypted secret for signature verification
- `expires_at`: subscription expiration
- Scopes: `active()`, `expiring($days)`, `byEventGroup($group)`

**FicEvent** (`app/Models/FicEvent.php`)
- `belongsTo` FicAccount
- `event_type`: CloudEvents type (e.g., `it.fattureincloud.webhooks.entities.clients.create`)
- `resource_type`: normalized (client, supplier, invoice, quote)
- `status`: pending → processed/failed
- `payload`: full webhook payload

## Important Patterns

### Creating New Models

Always create factories and seeders when creating models:
```bash
vendor/bin/sail artisan make:model FicDocument -mfs
# -m: migration, -f: factory, -s: seeder
```

### Form Validation

Always use Form Request classes (not inline validation):
```bash
vendor/bin/sail artisan make:request StoreFicDocumentRequest
```

Follow existing conventions in sibling Form Requests for array vs. string-based validation rules.

### Testing Requirements

**Every change MUST be tested.** After modifying code:

1. Update or create relevant test
2. Run specific test to verify: `vendor/bin/sail artisan test --compact --filter=testName`
3. Format code: `vendor/bin/sail bin pint --dirty`

**NEVER** remove tests without approval - they are core to the application.

### Token Refresh Flow

When `FicAccount::isTokenExpired()` returns true:
1. API calls receive 401
2. `FicApiService` marks account as `needs_refresh`
3. User redirected to OAuth flow (`/api/fic/oauth/redirect`)
4. New tokens obtained and stored encrypted
5. `FicConnectionService` cache cleared

### Cache Invalidation

After syncing FIC resources:
```php
app(FicCacheService::class)->invalidate('clients', $teamId);
app(FicCacheService::class)->invalidateAll($teamId);
```

## Known Issues

**Test Issues:**
- `FicSyncController` expects auth/team context (returns 500 without proper setup)
- `FicWebhookController` returns 401 in tests (API behind auth)
- `ProcessFicWebhookTest` has Log mock expectations that need adjustment

**Vite HMR with ngrok:** See `FIX_VITE_HMR.md` for configuration.

## Configuration

**Environment Variables:**
- FIC OAuth: `FIC_CLIENT_ID`, `FIC_CLIENT_SECRET`, `FIC_REDIRECT_URI`, `FIC_SCOPES`
- Webhook: `FIC_WEBHOOK_PUBLIC_KEY` (base64-encoded PEM for JWT verification)
- Queue: `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PORT`
- Reverb: `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`

**Laravel Boost Integration:**
This project uses Laravel Boost MCP server. Available tools:
- `search-docs`: Search Laravel ecosystem documentation (use BEFORE making changes)
- `tinker`: Execute PHP/Eloquent queries for debugging
- `database-query`: Read from database
- `browser-logs`: Read browser console logs
- `get-absolute-url`: Get correct project URL

## Multi-Tenant OAuth Roadmap

**Current Status:** Steps 1-2 mostly complete (see `docs/FIC-MULTI-TENANT-ROADMAP.md`)

**Remaining Work:**
- Step 4-7: Multi-tenant tests, migration seeder for existing credentials, security (policy, validation), user documentation
- UI for team FIC settings to configure OAuth credentials per team
- Currently falls back to `.env` credentials if team doesn't have configured credentials

## Document Generation Flow

1. User uploads `.docx` template with `${variable}` placeholders
2. `POST /api/fic/documents/extract-variables`: Parse template
3. `GET /api/fic/documents/resource?type=invoice&id=123`: Get available fields
4. User maps variables to flattened fields (auto-map available)
5. `POST /api/fic/documents/compile`: Generate filled document
6. Download compiled `.docx`

**Batch:** Upload template → select multiple resources → generate ZIP of documents

## Inertia.js Routes

- `/dashboard`: Main dashboard with FIC connection status
- `/fic/data`: Browse synced clients, suppliers, invoices, quotes
- `/fic/subscriptions/create`: Manage webhook subscriptions
- `/fic/documents/generate`: Single document generation
- `/fic/documents/generate/batch`: Batch document generation

## Git Workflow

Before commits:
```bash
vendor/bin/sail bin pint --dirty    # Format code
vendor/bin/sail artisan test --compact  # Run tests
```

## Code Style (Laravel Boost)

- Use PHP 8 constructor property promotion
- Always use explicit return type declarations
- Use curly braces for all control structures
- Use Eloquent relationships over raw queries
- Prefer `Model::query()` over `DB::`
- Use `config('app.name')` not `env('APP_NAME')` outside config files
- Use named routes with `route()` function
- Enum keys should be TitleCase
- Follow existing conventions in sibling files

## Frontend (Vue 3 + Inertia)

- Components in `resources/js/Pages`
- Use `<Link>` or `router.visit()` for navigation (not `<a>`)
- Use Inertia v2 `<Form>` component or `useForm` helper
- Tailwind CSS v3 for styling (use `gap` utilities for spacing, not margins)
- Support dark mode with `dark:` prefix if existing pages use it
