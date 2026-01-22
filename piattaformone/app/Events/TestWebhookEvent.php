<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Simple test event for testing real-time webhook visualization in the dashboard.
 * 
 * This event broadcasts immediately to test if the dashboard catches and visualizes it.
 */
class TestWebhookEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param int $accountId The account ID (default: 1, matching dashboard hardcoded value)
     * @param string $message Optional test message
     */
    public function __construct(
        public int $accountId = 1,
        public string $message = 'Test webhook event from tinker',
    ) {
        // Log the event creation
        Log::info('TestWebhookEvent: Event created', [
            'account_id' => $this->accountId,
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("webhooks.account.{$this->accountId}"),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'webhook.received';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $data = [
            'account_id' => $this->accountId,
            'event_group' => 'test',
            'event_type' => 'it.fattureincloud.webhooks.test.event',
            'ce_id' => 'test-' . now()->timestamp . '-' . uniqid(),
            'ce_time' => now()->toIso8601String(),
            'ce_subject' => 'test/webhook',
            'data' => [
                'message' => $this->message,
                'test' => true,
                'dispatched_at' => now()->toIso8601String(),
            ],
            'object_details' => [
                [
                    'type' => 'test',
                    'name' => 'Test Event',
                    'code' => 'TEST-' . now()->format('YmdHis'),
                ],
            ],
            'received_at' => now()->toIso8601String(),
        ];

        // Log what will be broadcast
        Log::info('TestWebhookEvent: Broadcasting event', [
            'account_id' => $this->accountId,
            'channel' => "webhooks.account.{$this->accountId}",
            'event_name' => 'webhook.received',
            'data' => $data,
        ]);

        return $data;
    }
}