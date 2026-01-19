<?php

namespace App\Console\Commands;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Command to manually test FIC webhook endpoints.
 *
 * This command helps test the webhook controller by simulating
 * webhook requests from Fatture in Cloud.
 */
class TestFicWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:test-webhook
                            {account_id : The FIC account ID}
                            {group=entity : The event group (entity, issued_documents, etc.)}
                            {--event=entity.create : The event type to simulate}
                            {--method=POST : HTTP method (GET for verification, POST for notification)}
                            {--base-url=http://localhost : Base URL of the application}
                            {--secret= : Webhook secret (if not provided, uses subscription secret)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test FIC webhook endpoints by simulating requests';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');
        $group = $this->argument('group');
        $method = strtoupper($this->option('method'));
        $baseUrl = rtrim($this->option('base-url'), '/');
        $event = $this->option('event');

        $this->info("Testing FIC Webhook");
        $this->line("Account ID: {$accountId}");
        $this->line("Event Group: {$group}");
        $this->line("HTTP Method: {$method}");
        $this->line("Base URL: {$baseUrl}");
        $this->newLine();

        // Find account
        $account = FicAccount::find($accountId);
        if (!$account) {
            $this->error("Account with ID {$accountId} not found.");
            return Command::FAILURE;
        }

        $url = "{$baseUrl}/api/webhooks/fic/{$accountId}/{$group}";

        if ($method === 'GET') {
            return $this->testSubscriptionVerification($url);
        }

        if ($method === 'POST') {
            return $this->testWebhookNotification($url, $accountId, $group, $event);
        }

        $this->error("Unsupported HTTP method: {$method}. Use GET or POST.");
        return Command::FAILURE;
    }

    /**
     * Test subscription verification (GET request).
     */
    private function testSubscriptionVerification(string $url): int
    {
        $challenge = 'test-challenge-' . Str::random(20);

        $this->info("Testing subscription verification...");
        $this->line("Challenge: {$challenge}");
        $this->newLine();

        try {
            $response = Http::withHeaders([
                'x-fic-verification-challenge' => $challenge,
            ])->get($url);

            $this->line("Status: {$response->status()}");
            $this->line("Response: " . $response->body());

            if ($response->successful() && $response->json('verification') === $challenge) {
                $this->newLine();
                $this->info('✓ Subscription verification test passed!');
                return Command::SUCCESS;
            }

            $this->newLine();
            $this->error('✗ Subscription verification test failed!');
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Test webhook notification (POST request).
     */
    private function testWebhookNotification(
        string $url,
        int $accountId,
        string $group,
        string $event
    ): int {
        $this->info("Testing webhook notification...");

        // Find subscription to get webhook secret
        $subscription = FicSubscription::where('fic_account_id', $accountId)
            ->where('event_group', $group)
            ->where('is_active', true)
            ->first();

        if (!$subscription) {
            $this->error("No active subscription found for account {$accountId} and group '{$group}'.");
            $this->line("Create one first using the RefreshFicSubscriptions command or manually.");
            return Command::FAILURE;
        }

        $secret = $this->option('secret') ?? $subscription->webhook_secret;

        if (empty($secret)) {
            $this->error("Webhook secret is required. Use --secret option or ensure subscription has a secret.");
            return Command::FAILURE;
        }

        // Create test payload
        $payload = $this->createTestPayload($event);

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $secret);

        $this->line("Event: {$event}");
        $this->line("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));
        $this->line("Signature: {$signature}");
        $this->newLine();

        try {
            $response = Http::withHeaders([
                'X-Fic-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            $this->line("Status: {$response->status()}");
            $this->line("Response: " . $response->body());

            if ($response->status() === 202) {
                $this->newLine();
                $this->info('✓ Webhook notification test passed! Job queued successfully.');
                return Command::SUCCESS;
            }

            $this->newLine();
            $this->error('✗ Webhook notification test failed!');
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Create test payload based on event type.
     */
    private function createTestPayload(string $event): array
    {
        $basePayload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
        ];

        if (str_starts_with($event, 'entity.')) {
            return array_merge($basePayload, [
                'data' => [
                    'id' => fake()->numberBetween(1000, 9999),
                    'name' => fake()->company(),
                    'type' => 'customer',
                    'code' => 'TEST-' . fake()->numerify('####'),
                ],
            ]);
        }

        if (str_starts_with($event, 'issued_document.') || str_starts_with($event, 'issued_documents.')) {
            return array_merge($basePayload, [
                'data' => [
                    'id' => fake()->numberBetween(1000, 9999),
                    'number' => fake()->numberBetween(1, 1000),
                    'type' => 'invoice',
                ],
            ]);
        }

        // Default payload
        return array_merge($basePayload, [
            'data' => [
                'id' => fake()->numberBetween(1000, 9999),
            ],
        ]);
    }
}
