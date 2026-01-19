<?php

namespace Tests\Unit;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class FicSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test FicSubscription belongs to FicAccount relationship.
     */
    public function test_fic_subscription_belongs_to_fic_account(): void
    {
        $account = FicAccount::factory()->create();
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
        ]);

        $this->assertInstanceOf(FicAccount::class, $subscription->ficAccount);
        $this->assertEquals($account->id, $subscription->ficAccount->id);
        $this->assertEquals($account->id, $subscription->fic_account_id);
    }

    /**
     * Test webhook_secret is encrypted when stored and decrypted when retrieved.
     */
    public function test_webhook_secret_is_encrypted(): void
    {
        $originalSecret = 'my-secret-webhook-key-12345';
        $subscription = FicSubscription::factory()->create([
            'webhook_secret' => $originalSecret,
        ]);

        // Refresh to get from database
        $subscription->refresh();

        // The value should be decrypted when accessed
        $this->assertEquals($originalSecret, $subscription->webhook_secret);

        // Verify it's stored encrypted in the database
        $rawValue = \DB::table('fic_subscriptions')
            ->where('id', $subscription->id)
            ->value('webhook_secret');

        // The raw value should be different from the original (encrypted)
        $this->assertNotEquals($originalSecret, $rawValue);
        $this->assertStringStartsWith('eyJpdiI6', $rawValue); // Laravel encrypted values start with this
    }

    /**
     * Test webhook_secret can be updated and remains encrypted.
     */
    public function test_webhook_secret_can_be_updated(): void
    {
        $subscription = FicSubscription::factory()->create([
            'webhook_secret' => 'original-secret',
        ]);

        $newSecret = 'new-secret-456';
        $subscription->update(['webhook_secret' => $newSecret]);

        $subscription->refresh();
        $this->assertEquals($newSecret, $subscription->webhook_secret);

        // Verify it's encrypted in database
        $rawValue = \DB::table('fic_subscriptions')
            ->where('id', $subscription->id)
            ->value('webhook_secret');

        $this->assertNotEquals($newSecret, $rawValue);
    }

    /**
     * Test expires_at is cast to datetime.
     */
    public function test_expires_at_is_cast_to_datetime(): void
    {
        $expiresAt = now()->addDays(30);
        $subscription = FicSubscription::factory()->create([
            'expires_at' => $expiresAt,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $subscription->expires_at);
        $this->assertEquals($expiresAt->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test is_active is cast to boolean.
     */
    public function test_is_active_is_cast_to_boolean(): void
    {
        $subscription = FicSubscription::factory()->create([
            'is_active' => true,
        ]);

        $this->assertIsBool($subscription->is_active);
        $this->assertTrue($subscription->is_active);

        $subscription->update(['is_active' => false]);
        $subscription->refresh();

        $this->assertFalse($subscription->is_active);
    }

    /**
     * Test active scope filters only active subscriptions.
     */
    public function test_active_scope_filters_active_subscriptions(): void
    {
        $active1 = FicSubscription::factory()->create(['is_active' => true]);
        $active2 = FicSubscription::factory()->create(['is_active' => true]);
        $inactive1 = FicSubscription::factory()->create(['is_active' => false]);
        $inactive2 = FicSubscription::factory()->create(['is_active' => false]);

        $activeSubscriptions = FicSubscription::active()->get();

        $this->assertCount(2, $activeSubscriptions);
        $this->assertTrue($activeSubscriptions->contains($active1));
        $this->assertTrue($activeSubscriptions->contains($active2));
        $this->assertFalse($activeSubscriptions->contains($inactive1));
        $this->assertFalse($activeSubscriptions->contains($inactive2));
    }

    /**
     * Test expiring scope filters subscriptions expiring within specified days.
     */
    public function test_expiring_scope_filters_expiring_subscriptions(): void
    {
        // Expiring in 10 days (should be found with default 15 days)
        $expiring1 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Expiring in 15 days (should be found with default 15 days)
        $expiring2 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(15),
            'is_active' => true,
        ]);

        // Expiring in 16 days (should NOT be found with default 15 days)
        $notExpiring1 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(16),
            'is_active' => true,
        ]);

        // No expiration date (should NOT be found)
        $noExpiration = FicSubscription::factory()->create([
            'expires_at' => null,
            'is_active' => true,
        ]);

        // Test with default 15 days
        $expiringSubscriptions = FicSubscription::expiring()->get();

        $this->assertCount(2, $expiringSubscriptions);
        $this->assertTrue($expiringSubscriptions->contains($expiring1));
        $this->assertTrue($expiringSubscriptions->contains($expiring2));
        $this->assertFalse($expiringSubscriptions->contains($notExpiring1));
        $this->assertFalse($expiringSubscriptions->contains($noExpiration));

        // Test with custom 20 days
        $expiringSubscriptions20 = FicSubscription::expiring(20)->get();

        $this->assertCount(3, $expiringSubscriptions20);
        $this->assertTrue($expiringSubscriptions20->contains($expiring1));
        $this->assertTrue($expiringSubscriptions20->contains($expiring2));
        $this->assertTrue($expiringSubscriptions20->contains($notExpiring1));
        $this->assertFalse($expiringSubscriptions20->contains($noExpiration));
    }

    /**
     * Test expiring scope with custom days parameter.
     */
    public function test_expiring_scope_with_custom_days(): void
    {
        // Expiring in 5 days
        $expiring5 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(5),
            'is_active' => true,
        ]);

        // Expiring in 10 days
        $expiring10 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Expiring in 20 days
        $expiring20 = FicSubscription::factory()->create([
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);

        // Test with 7 days
        $expiring7 = FicSubscription::expiring(7)->get();
        $this->assertCount(1, $expiring7);
        $this->assertTrue($expiring7->contains($expiring5));
        $this->assertFalse($expiring7->contains($expiring10));
        $this->assertFalse($expiring7->contains($expiring20));

        // Test with 25 days
        $expiring25 = FicSubscription::expiring(25)->get();
        $this->assertCount(3, $expiring25);
    }

    /**
     * Test byEventGroup scope filters by event group.
     */
    public function test_by_event_group_scope_filters_by_event_group(): void
    {
        $entity1 = FicSubscription::factory()->create(['event_group' => 'entity']);
        $entity2 = FicSubscription::factory()->create(['event_group' => 'entity']);
        $documents1 = FicSubscription::factory()->create(['event_group' => 'issued_documents']);
        $documents2 = FicSubscription::factory()->create(['event_group' => 'issued_documents']);
        $products1 = FicSubscription::factory()->create(['event_group' => 'products']);

        $entitySubscriptions = FicSubscription::byEventGroup('entity')->get();

        $this->assertCount(2, $entitySubscriptions);
        $this->assertTrue($entitySubscriptions->contains($entity1));
        $this->assertTrue($entitySubscriptions->contains($entity2));
        $this->assertFalse($entitySubscriptions->contains($documents1));
        $this->assertFalse($entitySubscriptions->contains($documents2));
        $this->assertFalse($entitySubscriptions->contains($products1));

        $documentsSubscriptions = FicSubscription::byEventGroup('issued_documents')->get();

        $this->assertCount(2, $documentsSubscriptions);
        $this->assertTrue($documentsSubscriptions->contains($documents1));
        $this->assertTrue($documentsSubscriptions->contains($documents2));
    }

    /**
     * Test scopes can be chained together.
     */
    public function test_scopes_can_be_chained(): void
    {
        $account = FicAccount::factory()->create();

        // Active, expiring, entity
        $match1 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Active, expiring, issued_documents (should not match)
        $notMatch1 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'issued_documents',
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        // Active, not expiring, entity (should not match)
        $notMatch2 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'expires_at' => now()->addDays(20),
            'is_active' => true,
        ]);

        // Inactive, expiring, entity (should not match)
        $notMatch3 = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'expires_at' => now()->addDays(10),
            'is_active' => false,
        ]);

        $results = FicSubscription::active()
            ->expiring(15)
            ->byEventGroup('entity')
            ->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($match1));
        $this->assertFalse($results->contains($notMatch1));
        $this->assertFalse($results->contains($notMatch2));
        $this->assertFalse($results->contains($notMatch3));
    }

    /**
     * Test relationship is properly loaded with eager loading.
     */
    public function test_relationship_can_be_eager_loaded(): void
    {
        $account = FicAccount::factory()->create(['name' => 'Test Account']);
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
        ]);

        // Query without eager loading
        $subscriptionWithoutEager = FicSubscription::find($subscription->id);
        $this->assertFalse($subscriptionWithoutEager->relationLoaded('ficAccount'));

        // Query with eager loading
        $subscriptionWithEager = FicSubscription::with('ficAccount')->find($subscription->id);
        $this->assertTrue($subscriptionWithEager->relationLoaded('ficAccount'));
        $this->assertEquals('Test Account', $subscriptionWithEager->ficAccount->name);
    }

    /**
     * Test fillable attributes are correct.
     */
    public function test_fillable_attributes_are_correct(): void
    {
        $subscription = new FicSubscription();
        $fillable = $subscription->getFillable();

        $expectedFillable = [
            'fic_account_id',
            'fic_subscription_id',
            'event_group',
            'webhook_secret',
            'expires_at',
            'is_active',
        ];

        $this->assertEquals($expectedFillable, $fillable);
    }

    /**
     * Test table name is correct.
     */
    public function test_table_name_is_correct(): void
    {
        $subscription = new FicSubscription();
        $this->assertEquals('fic_subscriptions', $subscription->getTable());
    }
}
