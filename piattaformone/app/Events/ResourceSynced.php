<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcasted when a FIC resource is successfully synced.
 *
 * This event is broadcasted to allow real-time updates in the frontend
 * when resources are synced from FIC API (via webhooks or manual sync).
 *
 * Uses ShouldBroadcastNow to broadcast immediately without queuing,
 * ensuring real-time delivery.
 */
class ResourceSynced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $resourceType  The resource type (client, supplier, invoice, quote)
     * @param  int  $ficId  The FIC resource ID
     * @param  int  $accountId  The FIC account ID
     * @param  string  $action  The action that triggered the sync (created, updated, deleted)
     * @param  array  $data  The synced resource data
     */
    public function __construct(
        public string $resourceType,
        public int $ficId,
        public int $accountId,
        public string $action,
        public array $data
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Broadcasts only to the account-specific channel for multi-tenant support.
     * Each account receives only its own sync events.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("sync.account.{$this->accountId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'resource.synced';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'fic_id' => $this->ficId,
            'account_id' => $this->accountId,
            'action' => $this->action,
            'data' => $this->data,
            'synced_at' => now()->toIso8601String(),
        ];
    }
}
