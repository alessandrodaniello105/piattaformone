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
        Mockery::close();
        parent::tearDown();
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

        // Mock FicApiService
        $this->mock(FicApiService::class, function ($mock) use ($account, $expiringSubscription) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->with(
                    $expiringSubscription->event_group,
                    Mockery::type('string')
                )
                ->andReturn([
                    'id' => $expiringSubscription->fic_subscription_id,
                    'secret' => 'new-secret-123',
                    'expires_at' => now()->addDays(30),
                ]);
        });

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('Refreshing FIC subscriptions expiring within 15 days')
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

        // Mock FicApiService
        $this->mock(FicApiService::class, function ($mock) use ($subscription, $newExpiresAt) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andReturn([
                    'id' => 'new-sub-id-123',
                    'secret' => 'new-webhook-secret-456',
                    'expires_at' => $newExpiresAt,
                ]);
        });

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("Processing subscription ID: {$subscription->id}")
            ->expectsOutput('✓ Successfully renewed')
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

        // Mock FicApiService to fail on first subscription, succeed on second
        $this->mock(FicApiService::class, function ($mock) use ($subscription1, $subscription2) {
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
                    'expires_at' => now()->addDays(30),
                ]);
        });

        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('info')->atLeast()->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 2 subscription(s) to renew:")
            ->expectsOutput('✗ Error: API Error')
            ->expectsOutput('✓ Successfully renewed')
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
            ->expectsOutput("✗ Account {$account->id} has no access token")
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

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->subDays(5), // Already expired
            'is_active' => true,
        ]);

        Log::shouldReceive('warning')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput('⚠ Subscription already expired')
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

        $this->mock(FicApiService::class, function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andThrow(new \RuntimeException('Rate limit exceeded', 429));
        });

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('✗ Rate limit exceeded: Rate limit exceeded')
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

        $this->mock(FicApiService::class, function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andThrow(new \RuntimeException('Unauthorized', 401));
        });

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput('✗ Authentication failed: Unauthorized')
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

        $this->mock(FicApiService::class, function ($mock) {
            $mock->shouldReceive('createOrRenewSubscription')
                ->once()
                ->andReturn([
                    'id' => 'sub-123',
                    'secret' => 'secret-123',
                    'expires_at' => now()->addDays(30),
                ]);
        });

        $this->artisan('fic:refresh-subscriptions', ['--days' => 25])
            ->expectsOutput('Refreshing FIC subscriptions expiring within 25 days')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->assertExitCode(0);
    }

    /**
     * Test command handles account not found gracefully.
     */
    public function test_command_handles_missing_account(): void
    {
        // Create subscription with non-existent account ID
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => 99999, // Non-existent account
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        Log::shouldReceive('error')->once();

        $this->artisan('fic:refresh-subscriptions')
            ->expectsOutput("Found 1 subscription(s) to renew:")
            ->expectsOutput("✗ Account not found for subscription {$subscription->id}")
            ->expectsOutput('Summary: 0 renewed, 1 failed')
            ->assertExitCode(1);
    }
}
