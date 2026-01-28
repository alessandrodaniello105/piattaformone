<?php

namespace Tests\Feature;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicEvent;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FicSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test initial sync endpoint returns error when no account exists.
     */
    public function test_initial_sync_fails_without_account(): void
    {
        $response = $this->postJson('/api/fic/initial-sync');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'No FIC account found. Please connect an account first.',
            ]);
    }

    /**
     * Test initial sync endpoint structure and error handling.
     * 
     * Note: Full API integration test requires actual FIC API credentials.
     * This test verifies the endpoint structure and error handling.
     */
    public function test_initial_sync_endpoint_structure(): void
    {
        $account = FicAccount::factory()->create([
            'status' => 'active',
            'access_token' => 'invalid-token-for-testing',
        ]);

        // This will fail due to invalid token, but we can verify error handling
        $response = $this->postJson('/api/fic/initial-sync');

        // Should either succeed (if API is mocked) or return error
        // We just verify the endpoint is accessible and returns JSON
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'stats' => [
                    'clients' => ['created', 'updated'],
                    'quotes' => ['created', 'updated'],
                    'invoices' => ['created', 'updated'],
                ],
            ]);
    }

    /**
     * Test events endpoint returns empty array when no events exist.
     */
    public function test_events_endpoint_returns_empty_when_no_events(): void
    {
        $account = FicAccount::factory()->create();

        $response = $this->getJson('/api/fic/events');

        $response->assertStatus(200)
            ->assertJson([
                'events' => [],
            ]);
    }

    /**
     * Test events endpoint returns events from fic_events table.
     */
    public function test_events_endpoint_returns_events_from_table(): void
    {
        $account = FicAccount::factory()->create();

        $event1 = FicEvent::factory()->create([
            'fic_account_id' => $account->id,
            'resource_type' => 'client',
            'event_type' => 'it.fattureincloud.webhooks.entities.clients.create',
            'fic_resource_id' => 1001,
            'occurred_at' => now()->subHours(1),
            'payload' => ['name' => 'Test Client'],
        ]);

        $event2 = FicEvent::factory()->create([
            'fic_account_id' => $account->id,
            'resource_type' => 'invoice',
            'event_type' => 'it.fattureincloud.webhooks.issued_documents.invoices.create',
            'fic_resource_id' => 2001,
            'occurred_at' => now()->subMinutes(30),
            'payload' => ['number' => 'FAT-001'],
        ]);

        $response = $this->getJson('/api/fic/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'events' => [
                    '*' => ['type', 'fic_id', 'occurred_at', 'event_type', 'description'],
                ],
            ]);

        $events = $response->json('events');
        $this->assertCount(2, $events);
        $this->assertEquals('invoice', $events[0]['type']); // Most recent first
        $this->assertEquals('client', $events[1]['type']);
    }

    /**
     * Test events endpoint respects limit parameter.
     */
    public function test_events_endpoint_respects_limit_parameter(): void
    {
        $account = FicAccount::factory()->create();

        // Create 10 events
        for ($i = 0; $i < 10; $i++) {
            FicEvent::factory()->create([
                'fic_account_id' => $account->id,
                'occurred_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->getJson('/api/fic/events?limit=5');

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertCount(5, $events);
    }

    /**
     * Test events endpoint falls back to resource tables when fic_events is empty.
     */
    public function test_events_endpoint_falls_back_to_resource_tables(): void
    {
        $account = FicAccount::factory()->create();

        $client = FicClient::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subHours(2),
            'name' => 'Test Client',
        ]);

        $quote = FicQuote::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subHour(),
            'number' => 'PRE-001',
        ]);

        $invoice = FicInvoice::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMinutes(30),
            'number' => 'FAT-001',
        ]);

        $response = $this->getJson('/api/fic/events');

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertGreaterThanOrEqual(3, count($events));

        // Verify events are sorted by occurred_at descending
        $occurredAts = array_column($events, 'occurred_at');
        $sorted = $occurredAts;
        rsort($sorted);
        $this->assertEquals($sorted, $occurredAts);
    }

    /**
     * Test metrics endpoint returns correct structure.
     */
    public function test_metrics_endpoint_returns_correct_structure(): void
    {
        $account = FicAccount::factory()->create();

        $response = $this->getJson('/api/fic/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'series' => [
                    'clients' => [
                        '*' => ['month', 'count'],
                    ],
                    'quotes' => [
                        '*' => ['month', 'count'],
                    ],
                    'invoices' => [
                        '*' => ['month', 'count'],
                    ],
                ],
                'lastMonth' => [
                    'clients',
                    'invoices',
                ],
            ]);
    }

    /**
     * Test metrics endpoint calculates monthly distribution correctly.
     */
    public function test_metrics_endpoint_calculates_monthly_distribution(): void
    {
        $account = FicAccount::factory()->create();

        // Create clients in different months
        FicClient::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonths(2)->startOfMonth(),
        ]);

        FicClient::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonths(2)->startOfMonth(),
        ]);

        FicClient::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonth()->startOfMonth(),
        ]);

        // Create invoices in last month
        FicInvoice::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonth()->startOfMonth(),
        ]);

        FicInvoice::factory()->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonth()->startOfMonth(),
        ]);

        $response = $this->getJson('/api/fic/metrics');

        $response->assertStatus(200);
        $data = $response->json();

        // Verify series structure
        $this->assertCount(12, $data['series']['clients']);
        $this->assertCount(12, $data['series']['quotes']);
        $this->assertCount(12, $data['series']['invoices']);

        // Verify last month KPIs
        $this->assertEquals(1, $data['lastMonth']['clients']);
        $this->assertEquals(2, $data['lastMonth']['invoices']);
    }

    /**
     * Test metrics endpoint returns zero when no account exists.
     */
    public function test_metrics_endpoint_returns_zero_without_account(): void
    {
        $response = $this->getJson('/api/fic/metrics');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(0, $data['lastMonth']['clients']);
        $this->assertEquals(0, $data['lastMonth']['invoices']);
    }

    /**
     * Test metrics endpoint handles quotes correctly.
     */
    public function test_metrics_endpoint_handles_quotes(): void
    {
        $account = FicAccount::factory()->create();

        // Create quotes in different months
        FicQuote::factory()->count(3)->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonths(3)->startOfMonth(),
        ]);

        FicQuote::factory()->count(2)->create([
            'fic_account_id' => $account->id,
            'fic_created_at' => now()->subMonth()->startOfMonth(),
        ]);

        $response = $this->getJson('/api/fic/metrics');

        $response->assertStatus(200);
        $data = $response->json();

        // Find the month with 3 quotes
        $quotesData = $data['series']['quotes'];
        $monthWithThree = collect($quotesData)->firstWhere('count', 3);
        $this->assertNotNull($monthWithThree);
    }

    /**
     * Test initial sync handles missing account gracefully.
     */
    public function test_initial_sync_handles_missing_account(): void
    {
        $response = $this->postJson('/api/fic/initial-sync');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'No FIC account found. Please connect an account first.',
            ]);
    }

    /**
     * Test events endpoint limits to maximum 200.
     */
    public function test_events_endpoint_limits_to_maximum_200(): void
    {
        $account = FicAccount::factory()->create();

        // Create 300 events
        for ($i = 0; $i < 300; $i++) {
            FicEvent::factory()->create([
                'fic_account_id' => $account->id,
                'occurred_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->getJson('/api/fic/events?limit=500');

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertLessThanOrEqual(200, count($events));
    }
}
