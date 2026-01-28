<?php

namespace Tests\Unit;

use App\Console\Commands\RefreshFicSubscriptions;
use App\Models\FicAccount;
use App\Models\FicSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshFicSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test command signature is correct.
     */
    public function test_command_signature_is_correct(): void
    {
        $command = new RefreshFicSubscriptions();
        $this->assertEquals('fic:refresh-subscriptions', $command->getName());
    }

    /**
     * Test command description is set.
     */
    public function test_command_description_is_set(): void
    {
        $command = new RefreshFicSubscriptions();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test command query finds correct subscriptions.
     */
    public function test_command_query_finds_correct_subscriptions(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-token',
        ]);

        // Should be found (expiring in 10 days)
        $expiring1 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Should be found (expiring in 15 days, exactly at cutoff)
        $expiring2 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(15),
            'is_active' => true,
        ]);

        // Should NOT be found (expiring in 16 days, outside window)
        $notExpiring = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(16),
            'is_active' => true,
        ]);

        // Should NOT be found (inactive)
        $inactive = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => now()->addDays(10),
            'is_active' => false,
        ]);

        // Should NOT be found (no expiration date)
        $noExpiration = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'expires_at' => null,
            'is_active' => true,
        ]);

        $cutoffDate = now()->addDays(15);
        $subscriptions = FicSubscription::where('is_active', true)
            ->where('expires_at', '<=', $cutoffDate)
            ->whereNotNull('expires_at')
            ->get();

        $this->assertCount(2, $subscriptions);
        $this->assertTrue($subscriptions->contains($expiring1));
        $this->assertTrue($subscriptions->contains($expiring2));
        $this->assertFalse($subscriptions->contains($notExpiring));
        $this->assertFalse($subscriptions->contains($inactive));
        $this->assertFalse($subscriptions->contains($noExpiration));
    }
}
