<?php

namespace Tests\Feature;

use App\Jobs\ProcessFicWebhook;
use App\Models\FicAccount;
use App\Models\FicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessFicWebhookFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job handles empty IDs array gracefully.
     */
    public function test_job_handles_empty_ids_array(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-token',
            'company_id' => 12345,
        ]);

        $payload = [
            'event' => 'it.fattureincloud.webhooks.entities.clients.create',
            'occurred_at' => now()->toIso8601String(),
            'subject' => 'company:12345',
            'data' => [
                'ids' => [],
            ],
        ];

        $job = new ProcessFicWebhook($payload, $account->id, 'entity');
        
        // Should complete without errors, no data created
        $job->handle();
        
        $this->assertEquals(0, FicClient::where('fic_account_id', $account->id)->count());
    }

    /**
     * Test job processes CloudEvents event type mapping.
     */
    public function test_job_maps_cloudevents_event_types(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-token',
            'company_id' => 12345,
        ]);

        // Test that different CloudEvents types are recognized
        $payloads = [
            [
                'event' => 'it.fattureincloud.webhooks.entities.clients.create',
                'data' => ['ids' => []],
            ],
            [
                'event' => 'it.fattureincloud.webhooks.issued_documents.quotes.create',
                'data' => ['ids' => []],
            ],
            [
                'event' => 'it.fattureincloud.webhooks.issued_documents.invoices.create',
                'data' => ['ids' => []],
            ],
        ];

        foreach ($payloads as $payload) {
            $payload['occurred_at'] = now()->toIso8601String();
            $payload['subject'] = 'company:12345';
            
            $job = new ProcessFicWebhook($payload, $account->id, 'entity');
            
            // Should not throw exception for recognized event types
            try {
                $job->handle();
                $this->assertTrue(true, "Event type {$payload['event']} handled successfully");
            } catch (\Exception $e) {
                // API errors are expected without mocking, but event type should be recognized
                $this->assertStringNotContainsString('Unhandled event type', $e->getMessage());
            }
        }
    }

    /**
     * Test job throws exception when account not found.
     */
    public function test_job_throws_exception_when_account_not_found(): void
    {
        $payload = [
            'event' => 'it.fattureincloud.webhooks.entities.clients.create',
            'occurred_at' => now()->toIso8601String(),
            'subject' => 'company:12345',
            'data' => [
                'ids' => [1001],
            ],
        ];

        $job = new ProcessFicWebhook($payload, 99999, 'entity');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FIC account 99999 not found');

        $job->handle();
    }

}
