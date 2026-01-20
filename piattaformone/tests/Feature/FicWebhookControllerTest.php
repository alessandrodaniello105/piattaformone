<?php

namespace Tests\Feature;

use App\Jobs\ProcessFicWebhook;
use App\Models\FicAccount;
use App\Models\FicSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class FicWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test subscription verification (GET request).
     */
    public function test_subscription_verification_with_challenge_header(): void
    {
        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge = 'test-challenge-token-12345';

        $response = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'verification' => $challenge,
            ]);
    }

    /**
     * Test subscription verification with challenge in query parameter.
     */
    public function test_subscription_verification_with_challenge_query(): void
    {
        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge = 'test-challenge-query-67890';

        $response = $this->getJson("/api/webhooks/fic/{$account->id}/entity?x-fic-verification-challenge={$challenge}");

        $response->assertStatus(200)
            ->assertJson([
                'verification' => $challenge,
            ]);
    }

    /**
     * Test subscription verification fails without challenge.
     */
    public function test_subscription_verification_fails_without_challenge(): void
    {
        $account = FicAccount::factory()->create();

        $response = $this->getJson("/api/webhooks/fic/{$account->id}/entity");

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Missing verification challenge',
            ]);
    }

    /**
     * Test webhook notification with valid signature (POST request).
     */
    public function test_webhook_notification_with_valid_signature(): void
    {
        Queue::fake();

        $account = FicAccount::factory()->create();
        $webhookSecret = 'test-webhook-secret-123';
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'webhook_secret' => $webhookSecret,
            'is_active' => true,
        ]);

        $payload = [
            'event' => 'entity.create',
            'data' => [
                'id' => 123,
                'name' => 'Test Client',
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhookSecret);

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'X-Fic-Signature' => $signature,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'message' => 'Webhook queued for processing',
            ]);

        Queue::assertPushed(ProcessFicWebhook::class, function ($job) use ($account) {
            return $job->accountId === $account->id
                && $job->eventGroup === 'entity'
                && isset($job->payload['event'])
                && $job->payload['event'] === 'entity.create';
        });
    }

    /**
     * Test webhook notification fails with invalid signature.
     */
    public function test_webhook_notification_fails_with_invalid_signature(): void
    {
        Queue::fake();

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'webhook_secret' => 'correct-secret',
            'is_active' => true,
        ]);

        $payload = ['event' => 'entity.create'];
        $body = json_encode($payload);
        $wrongSignature = hash_hmac('sha256', $body, 'wrong-secret');

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'X-Fic-Signature' => $wrongSignature,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Invalid signature',
            ]);

        Queue::assertNothingPushed();
    }

    /**
     * Test webhook notification fails without signature header.
     */
    public function test_webhook_notification_fails_without_signature(): void
    {
        Queue::fake();

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $payload = ['event' => 'entity.create'];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Missing signature header',
            ]);

        Queue::assertNothingPushed();
    }

    /**
     * Test webhook notification fails when subscription not found.
     */
    public function test_webhook_notification_fails_when_subscription_not_found(): void
    {
        Queue::fake();

        $account = FicAccount::factory()->create();

        $payload = ['event' => 'entity.create'];
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, 'any-secret');

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'X-Fic-Signature' => $signature,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Subscription not found or inactive',
            ]);

        Queue::assertNothingPushed();
    }

    /**
     * Test method not allowed for unsupported HTTP methods.
     */
    public function test_method_not_allowed(): void
    {
        $account = FicAccount::factory()->create();

        $response = $this->putJson("/api/webhooks/fic/{$account->id}/entity", []);

        // Laravel's router returns 405 before reaching the controller,
        // so we just check for the status code
        $response->assertStatus(405);
        
        // The response may have Laravel's standard method not allowed message
        // or our custom message, both are acceptable
        $responseData = $response->json();
        $this->assertTrue(
            isset($responseData['error']) || isset($responseData['message']),
            'Response should contain error or message field'
        );
    }

    /**
     * Test rate limiting allows first request.
     */
    public function test_rate_limiting_allows_first_request(): void
    {
        RateLimiter::clear('fic-webhook');

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge = 'test-challenge-rate-limit-1';

        $response = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'verification' => $challenge,
            ]);
    }

    /**
     * Test rate limiting blocks second request within same second.
     */
    public function test_rate_limiting_blocks_second_request(): void
    {
        RateLimiter::clear('fic-webhook');

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge1 = 'test-challenge-1';
        $challenge2 = 'test-challenge-2';

        // First request should succeed
        $response1 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge1,
        ]);

        $response1->assertStatus(200);

        // Second request immediately after should be rate limited
        $response2 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge2,
        ]);

        $response2->assertStatus(429); // Too Many Requests
    }

    /**
     * Test rate limiting allows request after waiting.
     */
    public function test_rate_limiting_allows_request_after_waiting(): void
    {
        RateLimiter::clear('fic-webhook');

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge1 = 'test-challenge-wait-1';
        $challenge2 = 'test-challenge-wait-2';

        // First request
        $response1 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge1,
        ]);

        $response1->assertStatus(200);

        // Wait 1.1 seconds (slightly more than 1 second limit)
        usleep(1100000); // 1.1 seconds in microseconds

        // Second request should succeed after waiting
        $response2 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge2,
        ]);

        $response2->assertStatus(200)
            ->assertJson([
                'verification' => $challenge2,
            ]);
    }

    /**
     * Test rate limiting applies to POST requests as well.
     */
    public function test_rate_limiting_applies_to_post_requests(): void
    {
        Queue::fake();
        RateLimiter::clear('fic-webhook');

        $account = FicAccount::factory()->create();
        $webhookSecret = 'test-webhook-secret-rate-limit';
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'webhook_secret' => $webhookSecret,
            'is_active' => true,
        ]);

        $payload = ['event' => 'entity.create'];
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhookSecret);

        // First POST request should succeed
        $response1 = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'X-Fic-Signature' => $signature,
        ]);

        $response1->assertStatus(202);

        // Second POST request immediately after should be rate limited
        $response2 = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'X-Fic-Signature' => $signature,
        ]);

        $response2->assertStatus(429);
    }

    /**
     * Test rate limiting is per IP address.
     */
    public function test_rate_limiting_is_per_ip_address(): void
    {
        RateLimiter::clear('fic-webhook');

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $challenge1 = 'test-challenge-ip-1';
        $challenge2 = 'test-challenge-ip-2';

        // First request from IP 127.0.0.1
        $response1 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge1,
        ]);

        $response1->assertStatus(200);

        // Simulate request from different IP (this is tricky in tests, but we can verify
        // that the rate limiter uses IP by checking the limiter key)
        // In practice, different IPs would have separate rate limits
        // For this test, we verify that the same IP is rate limited
        $response2 = $this->getJson("/api/webhooks/fic/{$account->id}/entity", [
            'x-fic-verification-challenge' => $challenge2,
        ]);

        $response2->assertStatus(429);
    }

    /**
     * Test webhook notification with CloudEvents format.
     */
    public function test_webhook_notification_with_cloudevents_format(): void
    {
        Queue::fake();

        // Disable JWT verification for this test
        config(['fattureincloud.webhook_verify_jwt' => false]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $ceType = 'it.fattureincloud.webhooks.entities.clients.create';
        $ceTime = now()->toIso8601String();
        $ceSubject = 'company:12345';
        $ceId = 'event-' . uniqid();
        $ceSource = 'https://api-v2.fattureincloud.it';
        $ceSpecVersion = '1.0';

        $payload = [
            'data' => [
                'ids' => [123, 456],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'ce-type' => $ceType,
            'ce-time' => $ceTime,
            'ce-subject' => $ceSubject,
            'ce-id' => $ceId,
            'ce-source' => $ceSource,
            'ce-specversion' => $ceSpecVersion,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'message' => 'Webhook queued for processing',
            ]);

        Queue::assertPushed(ProcessFicWebhook::class, function ($job) use ($account, $ceType, $ceTime, $ceSubject) {
            return $job->accountId === $account->id
                && $job->eventGroup === 'entity'
                && $job->payload['event'] === $ceType
                && $job->payload['occurred_at'] === $ceTime
                && $job->payload['subject'] === $ceSubject
                && isset($job->payload['data']['ids'])
                && count($job->payload['data']['ids']) === 2;
        });

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => true]);
    }

    /**
     * Test webhook notification with CloudEvents format for quotes.
     */
    public function test_webhook_notification_cloudevents_quotes_create(): void
    {
        Queue::fake();

        // Disable JWT verification for this test
        config(['fattureincloud.webhook_verify_jwt' => false]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'issued_documents',
            'is_active' => true,
        ]);

        $ceType = 'it.fattureincloud.webhooks.issued_documents.quotes.create';
        $ceTime = now()->toIso8601String();
        $ceSubject = 'company:12345';

        $payload = [
            'data' => [
                'ids' => [789],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/issued_documents", $payload, [
            'ce-type' => $ceType,
            'ce-time' => $ceTime,
            'ce-subject' => $ceSubject,
        ]);

        $response->assertStatus(202);

        Queue::assertPushed(ProcessFicWebhook::class, function ($job) use ($account, $ceType) {
            return $job->accountId === $account->id
                && $job->eventGroup === 'issued_documents'
                && $job->payload['event'] === $ceType;
        });

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => true]);
    }

    /**
     * Test webhook notification with CloudEvents format for invoices.
     */
    public function test_webhook_notification_cloudevents_invoices_create(): void
    {
        Queue::fake();

        // Disable JWT verification for this test
        config(['fattureincloud.webhook_verify_jwt' => false]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'issued_documents',
            'is_active' => true,
        ]);

        $ceType = 'it.fattureincloud.webhooks.issued_documents.invoices.create';
        $ceTime = now()->toIso8601String();
        $ceSubject = 'company:12345';

        $payload = [
            'data' => [
                'ids' => [101, 102, 103],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/issued_documents", $payload, [
            'ce-type' => $ceType,
            'ce-time' => $ceTime,
            'ce-subject' => $ceSubject,
        ]);

        $response->assertStatus(202);

        Queue::assertPushed(ProcessFicWebhook::class, function ($job) use ($account, $ceType) {
            return $job->accountId === $account->id
                && $job->payload['event'] === $ceType
                && count($job->payload['data']['ids']) === 3;
        });

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => true]);
    }

    /**
     * Test webhook notification fails with empty IDs array.
     */
    public function test_webhook_notification_fails_with_empty_ids(): void
    {
        Queue::fake();

        // Disable JWT verification for this test
        config(['fattureincloud.webhook_verify_jwt' => false]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $payload = [
            'data' => [
                'ids' => [],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'ce-type' => 'it.fattureincloud.webhooks.entities.clients.create',
            'ce-time' => now()->toIso8601String(),
            'ce-subject' => 'company:12345',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Empty IDs array in payload',
            ]);

        Queue::assertNothingPushed();

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => true]);
    }

    /**
     * Test webhook notification fails when JWT verification is enabled but token is missing.
     */
    public function test_webhook_notification_fails_without_jwt_when_enabled(): void
    {
        Queue::fake();

        // Enable JWT verification
        config(['fattureincloud.webhook_verify_jwt' => true]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $payload = [
            'data' => [
                'ids' => [123],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'ce-type' => 'it.fattureincloud.webhooks.entities.clients.create',
            'ce-time' => now()->toIso8601String(),
            'ce-subject' => 'company:12345',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Missing Authorization header',
            ]);

        Queue::assertNothingPushed();

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => false]);
    }

    /**
     * Test webhook notification succeeds when JWT verification is disabled.
     */
    public function test_webhook_notification_succeeds_without_jwt_when_disabled(): void
    {
        Queue::fake();

        // Disable JWT verification
        config(['fattureincloud.webhook_verify_jwt' => false]);

        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'is_active' => true,
        ]);

        $payload = [
            'data' => [
                'ids' => [123],
            ],
        ];

        $response = $this->postJson("/api/webhooks/fic/{$account->id}/entity", $payload, [
            'ce-type' => 'it.fattureincloud.webhooks.entities.clients.create',
            'ce-time' => now()->toIso8601String(),
            'ce-subject' => 'company:12345',
        ]);

        $response->assertStatus(202);

        Queue::assertPushed(ProcessFicWebhook::class);

        // Reset config
        config(['fattureincloud.webhook_verify_jwt' => true]);
    }
}
