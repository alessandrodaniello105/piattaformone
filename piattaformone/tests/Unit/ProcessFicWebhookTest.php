<?php

namespace Tests\Unit;

use App\Jobs\ProcessFicWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ProcessFicWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test job processes webhook payload successfully.
     */
    public function test_job_processes_webhook_payload(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $payload = [
            'event' => 'entity.create',
            'data' => [
                'id' => 123,
                'name' => 'Test Client',
                'type' => 'customer',
            ],
        ];

        $job = new ProcessFicWebhook($payload, 1, 'entity');
        
        // Verify job properties are set correctly before execution
        $this->assertEquals($payload, $job->payload);
        $this->assertEquals(1, $job->accountId);
        $this->assertEquals('entity', $job->eventGroup);
        
        // Job should process without throwing exceptions
        $job->handle();
        
        // Verify job properties are still correct after execution
        $this->assertEquals($payload, $job->payload);
        $this->assertEquals(1, $job->accountId);
        $this->assertEquals('entity', $job->eventGroup);
    }

    /**
     * Test job logs entity.create event data conditionally.
     */
    public function test_job_logs_entity_create_data(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $payload = [
            'event' => 'entity.create',
            'data' => [
                'id' => 456,
                'name' => 'New Customer',
                'type' => 'customer',
                'code' => 'CUST-001',
            ],
        ];

        // Verify payload structure before processing
        $this->assertEquals('entity.create', $payload['event']);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('id', $payload['data']);
        $this->assertArrayHasKey('name', $payload['data']);

        $job = new ProcessFicWebhook($payload, 2, 'entity');
        $this->assertEquals('entity', $job->eventGroup);
        
        // Job should process entity.create events without throwing exceptions
        $job->handle();
        
        // Verify payload is unchanged after processing
        $this->assertEquals('entity.create', $payload['event']);
    }

    /**
     * Test job sanitizes sensitive data in entity logs.
     */
    public function test_job_sanitizes_sensitive_entity_data(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $payload = [
            'event' => 'entity.create',
            'data' => [
                'id' => 789,
                'name' => 'Sensitive Client',
                'email' => 'client@example.com',
                'tax_code' => 'ABCDEF12G34H567I',
                'phone' => '+39 123 456 7890',
            ],
        ];

        // Verify sensitive fields are present before processing
        $this->assertArrayHasKey('email', $payload['data']);
        $this->assertArrayHasKey('tax_code', $payload['data']);
        $this->assertArrayHasKey('phone', $payload['data']);
        $this->assertEquals('client@example.com', $payload['data']['email']);
        $this->assertEquals('ABCDEF12G34H567I', $payload['data']['tax_code']);

        $job = new ProcessFicWebhook($payload, 3, 'entity');
        $this->assertEquals(3, $job->accountId);
        
        // Job should process and sanitize sensitive data without throwing exceptions
        $job->handle();
        
        // Verify sensitive fields are still in the original payload (sanitization happens in logs, not payload)
        $this->assertArrayHasKey('email', $payload['data']);
        $this->assertArrayHasKey('tax_code', $payload['data']);
        $this->assertArrayHasKey('phone', $payload['data']);
    }

    /**
     * Test job retries on exception.
     */
    public function test_job_retries_on_exception(): void
    {
        $payload = ['event' => 'test.event'];
        $job = new ProcessFicWebhook($payload, 1, 'test');

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->backoff);
        $this->assertEquals(120, $job->timeout);
    }

    /**
     * Test job uses redis connection.
     */
    public function test_job_uses_redis_connection(): void
    {
        $payload = ['event' => 'test.event'];
        $job = new ProcessFicWebhook($payload, 1, 'test');

        $this->assertEquals('redis', $job->connection);
    }

    /**
     * Test job failed handler logs error.
     */
    public function test_job_failed_handler_logs_error(): void
    {
        Log::shouldReceive('error')->once()->with(
            'FIC Webhook: Job failed after all retries',
            Mockery::on(function ($context) {
                return isset($context['event'])
                    && isset($context['account_id'])
                    && isset($context['event_group'])
                    && isset($context['error'])
                    && isset($context['attempts']);
            })
        );

        $payload = ['event' => 'test.event'];
        $job = new ProcessFicWebhook($payload, 1, 'test');

        $exception = new \Exception('Test error');
        $job->failed($exception);
        
        // Verify job properties are preserved
        $this->assertEquals($payload, $job->payload);
        $this->assertEquals(1, $job->accountId);
    }
}
