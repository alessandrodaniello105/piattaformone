<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcasted when a webhook is received from Fatture in Cloud.
 * 
 * This event is broadcasted to allow real-time monitoring of webhook
 * notifications in the frontend.
 * 
 * Uses ShouldBroadcastNow to broadcast immediately without queuing,
 * ensuring real-time delivery during HTTP request handling.
 */
class WebhookReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param int $accountId The FIC account ID
     * @param string $eventGroup The event group (e.g., 'entity', 'issued_documents')
     * @param string $eventType The CloudEvents type
     * @param array $data The webhook data
     * @param string|null $ceId The CloudEvents ID
     * @param string|null $ceTime The CloudEvents time
     * @param string|null $ceSubject The CloudEvents subject
     * @param array|null $objectDetails Basic details of the affected object(s) for display
     */
    public function __construct(
        public int $accountId,
        public string $eventGroup,
        public string $eventType,
        public array $data,
        public ?string $ceId = null,
        public ?string $ceTime = null,
        public ?string $ceSubject = null,
        public ?array $objectDetails = null,
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     * 
     * Broadcasts only to the account-specific channel for multi-tenant support.
     * Each account receives only its own webhook events.
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
        return [
            'account_id' => $this->accountId,
            'event_group' => $this->eventGroup,
            'event_type' => $this->eventType,
            'ce_id' => $this->ceId,
            'ce_time' => $this->ceTime,
            'ce_subject' => $this->ceSubject,
            'data' => $this->data,
            'object_details' => $this->objectDetails,
            'received_at' => now()->toIso8601String(),
        ];
    }
}
