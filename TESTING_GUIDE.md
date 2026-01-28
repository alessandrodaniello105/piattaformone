# Testing Guide for FIC Multi-Tenant Components

This guide explains how to test the three implemented components: `FicApiService`, `FicWebhookController`, and `ProcessFicWebhook` Job.

## Test Files Created

### 1. Feature Tests
- **`tests/Feature/FicWebhookControllerTest.php`** - Tests the webhook controller endpoints

### 2. Unit Tests
- **`tests/Unit/FicApiServiceTest.php`** - Tests the FIC API service
- **`tests/Unit/ProcessFicWebhookTest.php`** - Tests the webhook processing job

### 3. Factories
- **`database/factories/FicAccountFactory.php`** - Factory for creating test FIC accounts
- **`database/factories/FicSubscriptionFactory.php`** - Factory for creating test subscriptions

### 4. Manual Testing Command
- **`app/Console/Commands/TestFicWebhook.php`** - Artisan command for manual webhook testing

## Running Tests

> **Note:** This project uses Laravel Sail. 
> 
> - Use `./vendor/bin/sail artisan` instead of `php artisan`
> - If you have a sail alias set up, you can use `sail artisan` instead
> - All commands in this guide assume you're using Sail

### Run All Tests
```bash
./vendor/bin/sail artisan test
```

### Run Specific Test Suites
```bash
# Run all feature tests
./vendor/bin/sail artisan test --testsuite=Feature

# Run all unit tests
./vendor/bin/sail artisan test --testsuite=Unit

# Run specific test file
./vendor/bin/sail artisan test tests/Feature/FicWebhookControllerTest.php

# Run specific test method
./vendor/bin/sail artisan test --filter test_subscription_verification_with_challenge_header
```

### Run Tests with Coverage
```bash
./vendor/bin/sail artisan test --coverage
```

## Manual Testing

### 1. Test Webhook Endpoint (GET - Subscription Verification)

Using the artisan command:
```bash
./vendor/bin/sail artisan fic:test-webhook 1 entity --method=GET --base-url=http://localhost
```

Using curl:
```bash
curl -X GET "http://localhost/api/webhooks/fic/1/entity" \
  -H "x-fic-verification-challenge: test-challenge-123"
```

Expected response:
```json
{
  "verification": "test-challenge-123"
}
```

### 2. Test Webhook Endpoint (POST - Notification)

First, ensure you have:
1. A `FicAccount` record with ID 1
2. An active `FicSubscription` for account 1 with event_group 'entity' and a webhook_secret

Using the artisan command:
```bash
./vendor/bin/sail artisan fic:test-webhook 1 entity \
  --method=POST \
  --event=entity.create \
  --base-url=http://localhost \
  --secret=your-webhook-secret
```

Using curl:
```bash
# Calculate signature first
PAYLOAD='{"event":"entity.create","data":{"id":123,"name":"Test Client"}}'
SECRET="your-webhook-secret"
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

curl -X POST "http://localhost/api/webhooks/fic/1/entity" \
  -H "Content-Type: application/json" \
  -H "X-Fic-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

Expected response:
```json
{
  "status": "accepted",
  "message": "Webhook queued for processing"
}
```

### 3. Check Queued Jobs

After sending a POST request, check if the job was queued:

```bash
# Check Redis queue (if using Redis)
./vendor/bin/sail artisan queue:work --once

# Or listen to queue
./vendor/bin/sail artisan queue:listen redis
```

### 4. Check Logs

View logs to see if webhook was processed:
```bash
# View logs in real-time
./vendor/bin/sail logs -f laravel

# Or from host machine
tail -f storage/logs/laravel.log
```

Or use Laravel Pail:
```bash
./vendor/bin/sail artisan pail
```

## Test Data Setup

### Create Test Account and Subscription

You can use Tinker or create a seeder:

```php
// Using Tinker
./vendor/bin/sail artisan tinker

$account = App\Models\FicAccount::create([
    'name' => 'Test Account',
    'company_id' => 1234567,
    'company_name' => 'Test Company',
    'access_token' => 'test-access-token',
    'refresh_token' => 'test-refresh-token',
    'status' => 'active',
]);

$subscription = App\Models\FicSubscription::create([
    'fic_account_id' => $account->id,
    'fic_subscription_id' => 'sub_test_123',
    'event_group' => 'entity',
    'webhook_secret' => 'test-secret-key',
    'expires_at' => now()->addDays(30),
    'is_active' => true,
]);
```

Or use factories in tests:
```php
$account = FicAccount::factory()->create();
$subscription = FicSubscription::factory()->create([
    'fic_account_id' => $account->id,
    'event_group' => 'entity',
]);
```

## Testing Checklist

### FicApiService
- [x] SDK initialization with valid token
- [x] SDK initialization fails without token
- [x] SDK initialization is cached
- [ ] createOrRenewSubscription with mock API (requires mocking HTTP client)

### FicWebhookController
- [x] GET request with challenge header
- [x] GET request with challenge query parameter
- [x] GET request fails without challenge
- [x] POST request with valid signature
- [x] POST request fails with invalid signature
- [x] POST request fails without signature
- [x] POST request fails when subscription not found
- [x] Method not allowed for unsupported methods

### ProcessFicWebhook Job
- [x] Job processes webhook payload
- [x] Job logs entity.create event data
- [x] Job sanitizes sensitive data
- [x] Job retry configuration
- [x] Job uses Redis connection
- [x] Job failed handler logs error

## Troubleshooting

### Tests Fail with "Class not found"
Make sure factories are properly autoloaded:
```bash
./vendor/bin/sail composer dump-autoload
```

### Queue Jobs Not Processing
Ensure Redis is running and configured:
```bash
# Check if Redis container is running
./vendor/bin/sail ps

# Check Redis connection
./vendor/bin/sail artisan tinker
>>> Redis::ping()
```

### Sail Container Issues
If you encounter issues with Sail commands:
```bash
# Restart containers
./vendor/bin/sail down
./vendor/bin/sail up -d

# Check container status
./vendor/bin/sail ps

# View logs
./vendor/bin/sail logs
```

### Webhook Signature Validation Fails
- Ensure the webhook_secret in the database matches the one used to generate the signature
- Remember that webhook_secret is encrypted in the model, so use the actual secret value
- Check that the raw request body matches exactly (whitespace matters)

## Next Steps

1. Add integration tests that actually call the FIC API (with test credentials)
2. Add end-to-end tests that simulate the full webhook flow
3. Add performance tests for high-volume webhook processing
4. Add tests for the `RefreshFicSubscriptions` command (when implemented)
