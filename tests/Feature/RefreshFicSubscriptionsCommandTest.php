<?php

namespace Tests\Feature;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RefreshFicSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Clear any command bindings to avoid transaction issues
        if ($this->app->bound(\App\Console\Commands\RefreshFicSubscriptions::class)) {
            $this->app->forgetInstance(\App\Console\Commands\RefreshFicSubscriptions::class);
        }
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a test command instance with a mocked FicApiService.
     *
     * @param callable $mockSetup Callback to configure the mock
     * @return void
     */
    protected function createTestCommandWithMockedService(callable $mockSetup): void
    {
        $mockService = Mockery::mock(FicApiService::class);
        $mockSetup($mockService);

        // Create the test command class
        $testCommandClass = new class($mockService) extends \App\Console\Commands\RefreshFicSubscriptions {
            private $mockService;
            
            public function __construct($mockService) {
                parent::__construct();
                $this->mockService = $mockService;
            }
            
            public function createFicApiService(\App\Models\FicAccount $account, ?\GuzzleHttp\Client $httpClient = null): \App\Services\FicApiService {
                return $this->mockService;
            }
        };

        // Bind the test command in the container just before use
        $this->app->bind(\App\Console\Commands\RefreshFicSubscriptions::class, function () use ($testCommandClass) {
            return $testCommandClass;
        });
    }

    /**
     * Test command finds subscriptions expiring within 15 days.
     */
    public function test_command_finds_subscriptions_expiring_within_15_days(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
        ]);

        // Create subscription expiring in 10 days
        $expiringSubscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Create subscription expiring in 20 days (should not be found)
        $notExpiringSubscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);

        $newExpiresAt = now()->addDays(30);

        // Create a test command with mocked FicApiService
        $this->createTestCommandWithMockedService(function ($mock) use ($expiringSubscription, $newExpiresAt) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andReturn([
                    'id' => $expiringSubscription->fic_subscription_id,
                    'secret' => 'new-secret-123',
                    'expires_at' => $newExpiresAt,
                ]);
        });

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->assertExitCode(0);

        // Verify subscription was updated
        $expiringSubscription->refresh();
        $this->assertEquals('new-secret-123', $expiringSubscription->webhook_secret);
        $this->assertTrue($expiringSubscription->is_active);
    }

    /**
     * Test command renews subscription with success.
     */
    public function test_command_renews_subscription_successfully(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
            'name' => 'Test Account',
        ]);

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
            'event_group' => 'entity',
        ]);

        $newExpiresAt = now()->addDays(30);

        // Create a test command with mocked FicApiService
        $this->createTestCommandWithMockedService(function ($mock) use ($subscription, $newExpiresAt) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andReturn([
                    'id' => 'new-sub-id-123',
                    'secret' => 'new-webhook-secret-456',
                    'expires_at' => $newExpiresAt,
                ]);
        });

        $expiresAtFormatted = $newExpiresAt->format('Y-m-d H:i:s');
        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("Processing subscription ID: {$subscription->id} (Account: {$account->id}, Group: {$subscription->event_group})")
            ->expectsOutput("  ✓ Successfully renewed (expires: {$expiresAtFormatted})")
            ->expectsOutput('Summary: 1 renewed, 0 failed')
            ->assertExitCode(0);

        // Verify subscription was updated
        $subscription->refresh();
        $this->assertEquals('new-sub-id-123', $subscription->fic_subscription_id);
        $this->assertEquals('new-webhook-secret-456', $subscription->webhook_secret);
        $this->assertEquals($newExpiresAt->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test command handles error on single subscription and continues with others.
     */
    public function test_command_handles_error_and_continues_with_others(): void
    {
        $account1 = FicAccount::factory()->create([
            'access_token' => 'test-access-token-1',
        ]);

        $account2 = FicAccount::factory()->create([
            'access_token' => 'test-access-token-2',
        ]);

        $subscription1 = FicSubscription::factory()->create([
            'fic_account_id' => $account1->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        $subscription2 = FicSubscription::factory()->create([
            'fic_account_id' => $account2->id,
            'expires_at' => now()->addDays(12),
            'is_active' => true,
        ]);

        $newExpiresAt = now()->addDays(30);
        $newExpiresAtFormatted = $newExpiresAt->format('Y-m-d H:i:s');

        // Create a test command with mocked FicApiService to fail on first subscription, succeed on second
        $this->createTestCommandWithMockedService(function ($mock) use ($subscription1, $subscription2, $newExpiresAt) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->with($subscription1->event_group, Mockery::type('string'))
                ->andThrow(new \RuntimeException('API Error', 500));

            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->with($subscription2->event_group, Mockery::type('string'))
                ->andReturn([
                    'id' => $subscription2->fic_subscription_id,
                    'secret' => 'new-secret',
                    'expires_at' => $newExpiresAt,
                ]);
        });

        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('info')->atLeast()->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 2 subscription(s) to renew:")
            ->expectsOutput('  ✗ Error: API Error')
            ->expectsOutput("  ✓ Successfully renewed (expires: {$newExpiresAtFormatted})")
            ->expectsOutput('Summary: 1 renewed, 1 failed')
            ->assertExitCode(1); // Command fails when there are errors
    }

    /**
     * Test command skips subscriptions with account missing access token.
     */
    public function test_command_skips_subscription_without_access_token(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => null, // No access token
        ]);

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        Log::shouldReceive('warning')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("  ✗ Account {$account->id} has no access token")
            ->expectsOutput('Summary: 0 renewed, 1 failed')
            ->assertExitCode(1);
    }

    /**
     * Test command skips already expired subscriptions.
     */
    public function test_command_skips_expired_subscriptions(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
        ]);

        $expiredDate = now()->subDays(5);
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => $expiredDate, // Already expired
            'is_active' => true,
        ]);

        Log::shouldReceive('warning')->once();

        $expiredDateFormatted = $expiredDate->format('Y-m-d H:i:s');
        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("  ⚠ Subscription already expired on {$expiredDateFormatted}")
            ->expectsOutput('Summary: 0 renewed, 0 failed')
            ->assertExitCode(0);
    }

    /**
     * Test command handles rate limiting (429 error).
     */
    public function test_command_handles_rate_limiting(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
        ]);

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Create a test command with mocked FicApiService
        $this->createTestCommandWithMockedService(function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andThrow(new \RuntimeException('Rate limit exceeded', 429));
        });

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('  ✗ Rate limit exceeded: Rate limit exceeded')
            ->expectsOutput('Summary: 0 renewed, 1 failed')
            ->assertExitCode(1);
    }

    /**
     * Test command handles authentication failure (401 error).
     */
    public function test_command_handles_authentication_failure(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
        ]);

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Create a test command with mocked FicApiService
        $this->createTestCommandWithMockedService(function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andThrow(new \RuntimeException('Unauthorized', 401));
        });

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('  ✗ Authentication failed: Unauthorized')
            ->expectsOutput('Summary: 0 renewed, 1 failed')
            ->assertExitCode(1);
    }

    /**
     * Test command returns success when no subscriptions need renewal.
     */
    public function test_command_returns_success_when_no_subscriptions_found(): void
    {
        // Create subscription expiring in 20 days (outside 15-day window)
        $account = FicAccount::factory()->create();
        FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('No subscriptions found that need renewal.')
            ->assertExitCode(0);
    }

    /**
     * Test command respects custom days option.
     */
    public function test_command_respects_custom_days_option(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token',
        ]);

        // Create subscription expiring in 20 days
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);

        // Create a test command with mocked FicApiService
        $this->createTestCommandWithMockedService(function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andReturn([
                    'id' => 'sub-123',
                    'secret' => 'secret-123',
                    'expires_at' => now()->addDays(30),
                ]);
        });

        $this->artisan('fic:refresh-subscriptions', ['--days' => 25])
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->assertExitCode(0);
    }

    /**
     * Test command handles account not found gracefully.
     * 
     * Note: This test simulates a scenario where a subscription references
     * a non-existent account. In production, this shouldn't happen due to
     * foreign key constraints with CASCADE, but we test error handling anyway.
     * 
     * Since SQLite enforces foreign keys strictly and we're using :memory: database,
     * we skip this test when using SQLite as it's not possible to bypass the constraint
     * in a reliable way. This test would work with PostgreSQL.
     */
    public function test_command_handles_missing_account(): void
    {
        // Skip this test if using SQLite as it enforces foreign keys strictly
        // and we can't reliably bypass them in :memory: database
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Cannot test missing account scenario with SQLite foreign key constraints');
        }

        // Create subscription data
        $subscriptionData = FicSubscription::factory()->make([
            'fic_account_id' => 99999, // Invalid account ID
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ])->getAttributes();

        $now = now()->toDateTimeString();

        // For PostgreSQL, we can use raw SQL to insert with invalid account ID
        \Illuminate\Support\Facades\DB::unprepared("
            INSERT INTO fic_subscriptions 
            (fic_account_id, fic_subscription_id, event_group, webhook_secret, expires_at, is_active, created_at, updated_at)
            VALUES (99999, '{$subscriptionData['fic_subscription_id']}', '{$subscriptionData['event_group']}', 
                    '{$subscriptionData['webhook_secret']}', '{$subscriptionData['expires_at']}', 1, '{$now}', '{$now}')
        ");

        $subscriptionId = \Illuminate\Support\Facades\DB::getPdo()->lastInsertId();

        // Load the subscription model
        $subscription = FicSubscription::find($subscriptionId);
        $this->assertNotNull($subscription);

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("  ✗ Account not found for subscription {$subscription->id}")
            ->expectsOutput('Summary: 0 renewed, 1 failed')
            ->assertExitCode(1);
    }
}
