<?php

namespace App\Services;

use App\Models\FicAccount;
use FattureInCloud\Configuration;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with Fatture in Cloud API.
 *
 * This service initializes the FIC SDK with account credentials
 * and provides methods for managing subscriptions.
 */
class FicApiService
{
    private ?Configuration $config = null;

    private ?Client $httpClient = null;

    private FicAccount $account;

    /**
     * Create a new FicApiService instance.
     *
     * @param FicAccount $account The FIC account to use for API calls
     */
    public function __construct(FicAccount $account)
    {
        $this->account = $account;
    }

    /**
     * Initialize the FIC SDK with the account's access token.
     *
     * Creates a Configuration instance with the decrypted access token
     * from the FicAccount model.
     *
     * @return Configuration The initialized configuration
     * @throws \RuntimeException If access token is missing or invalid
     */
    public function initializeSdk(): Configuration
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $accessToken = $this->account->access_token;

        if (empty($accessToken)) {
            throw new \RuntimeException(
                "Access token is missing for FIC account ID: {$this->account->id}"
            );
        }

        $this->config = Configuration::getDefaultConfiguration();
        $this->config->setAccessToken($accessToken);

        // Create HTTP client
        $this->httpClient = new Client([
            'timeout' => 30.0,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        return $this->config;
    }

    /**
     * Create or renew a webhook subscription.
     *
     * Uses the FIC API to create a new subscription or renew an existing one.
     * If the SDK provides SubscriptionApi, it uses that; otherwise, it makes
     * direct HTTP calls to the FIC API.
     *
     * @param string $eventGroup The event group (e.g., 'entity', 'issued_documents')
     * @param string $webhookUrl The webhook URL to receive events
     * @return array Subscription data with keys: id, secret, expires_at
     * @throws \Exception If the API call fails
     */
    public function createOrRenewSubscription(string $eventGroup, string $webhookUrl): array
    {
        // Ensure SDK is initialized
        $this->initializeSdk();

        try {
            // Try to use SubscriptionApi if available
            if (class_exists(\FattureInCloud\Api\SubscriptionsApi::class)) {
                return $this->createOrRenewSubscriptionViaApi($eventGroup, $webhookUrl);
            }

            // Fallback to direct HTTP call
            return $this->createOrRenewSubscriptionViaHttp($eventGroup, $webhookUrl);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            // Handle rate limiting (429)
            if ($statusCode === 429) {
                $retryAfter = $e->getResponse()?->getHeader('Retry-After')[0] ?? '60';
                Log::warning('FIC API: Rate limit exceeded', [
                    'account_id' => $this->account->id,
                    'event_group' => $eventGroup,
                    'retry_after' => $retryAfter,
                ]);

                throw new \RuntimeException(
                    "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
                    429
                );
            }

            // Handle unauthorized (401) - credentials expired
            if ($statusCode === 401) {
                Log::error('FIC API: Unauthorized - credentials may be expired', [
                    'account_id' => $this->account->id,
                    'event_group' => $eventGroup,
                ]);

                // Mark account as needing refresh
                $this->account->update([
                    'status' => 'needs_refresh',
                    'status_note' => 'Access token expired or invalid',
                ]);

                throw new \RuntimeException(
                    'Authentication failed. Account credentials may be expired. Account marked for refresh.',
                    401
                );
            }

            Log::error('FIC API: Client error creating/renewing subscription', [
                'account_id' => $this->account->id,
                'event_group' => $eventGroup,
                'status_code' => $statusCode,
                'response' => $responseBody,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "FIC API error (HTTP {$statusCode}): Failed to create/renew subscription",
                $statusCode,
                $e
            );
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 500;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Server error creating/renewing subscription', [
                'account_id' => $this->account->id,
                'event_group' => $eventGroup,
                'status_code' => $statusCode,
                'response' => $responseBody,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "FIC API server error (HTTP {$statusCode}). Please try again later.",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error creating/renewing subscription', [
                'account_id' => $this->account->id,
                'event_group' => $eventGroup,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * Create or renew subscription using SubscriptionApi (SDK method).
     *
     * @param string $eventGroup
     * @param string $webhookUrl
     * @return array
     */
    private function createOrRenewSubscriptionViaApi(string $eventGroup, string $webhookUrl): array
    {
        $subscriptionsApi = new \FattureInCloud\Api\SubscriptionsApi($this->httpClient, $this->config);

        // Check if subscription exists
        $existingSubscription = $this->account->subscriptions()
            ->where('event_group', $eventGroup)
            ->where('is_active', true)
            ->first();

        if ($existingSubscription) {
            // Renew existing subscription
            // Note: Actual method name may vary based on SDK implementation
            $response = $subscriptionsApi->renewSubscription(
                $this->account->company_id,
                $existingSubscription->fic_subscription_id
            );
        } else {
            // Create new subscription
            $response = $subscriptionsApi->createSubscription(
                $this->account->company_id,
                [
                    'event_type' => $eventGroup,
                    'webhook_url' => $webhookUrl,
                ]
            );
        }

        // Extract subscription data from response
        // Note: Response structure may vary - adjust based on actual SDK response
        $data = $response->getData();

        return [
            'id' => $data->getId() ?? $existingSubscription->fic_subscription_id ?? null,
            'secret' => $data->getSecret() ?? null,
            'expires_at' => $data->getExpiresAt() ? new \Carbon\Carbon($data->getExpiresAt()) : null,
        ];
    }

    /**
     * Create or renew subscription using direct HTTP calls.
     *
     * This is a fallback method when SubscriptionApi is not available in the SDK.
     *
     * @param string $eventGroup
     * @param string $webhookUrl
     * @return array
     */
    private function createOrRenewSubscriptionViaHttp(string $eventGroup, string $webhookUrl): array
    {
        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        // Check if subscription exists
        $existingSubscription = $this->account->subscriptions()
            ->where('event_group', $eventGroup)
            ->where('is_active', true)
            ->first();

        $url = $existingSubscription
            ? "{$baseUrl}/c/{$companyId}/subscriptions/{$existingSubscription->fic_subscription_id}"
            : "{$baseUrl}/c/{$companyId}/subscriptions";

        $method = $existingSubscription ? 'PUT' : 'POST';

        $payload = [
            'event_type' => $eventGroup,
            'webhook_url' => $webhookUrl,
        ];

        $response = $this->httpClient->request($method, $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $responseData = json_decode($response->getBody()->getContents(), true);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                "FIC API returned HTTP {$statusCode}: " . ($responseData['error'] ?? 'Unknown error'),
                $statusCode
            );
        }

        return [
            'id' => $responseData['data']['id'] ?? $existingSubscription->fic_subscription_id ?? null,
            'secret' => $responseData['data']['secret'] ?? null,
            'expires_at' => isset($responseData['data']['expires_at'])
                ? new \Carbon\Carbon($responseData['data']['expires_at'])
                : null,
        ];
    }

    /**
     * Create or renew subscription for a specific event type using the new API format.
     *
     * This method uses the new FIC API format with 'sink' and 'types' array
     * for subscribing to specific events like 'it.fattureincloud.webhooks.entities.clients.create'.
     *
     * @param string|array $eventTypes Event type(s) - can be a single string or array of strings
     * @param string $webhookUrl The webhook URL (sink)
     * @param string|null $eventGroup Optional event group for database storage/routing
     * @return array Subscription data with keys: id, secret, expires_at
     * @throws \Exception If the API call fails
     */
    public function createOrRenewSubscriptionForEventType(
        string|array $eventTypes,
        string $webhookUrl,
        ?string $eventGroup = null
    ): array {
        // Ensure SDK is initialized
        $this->initializeSdk();

        // Normalize event types to array
        $eventTypesArray = is_array($eventTypes) ? $eventTypes : [$eventTypes];

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        // Check if subscription exists (by event group if provided, otherwise by first event type)
        $existingSubscription = null;
        if ($eventGroup) {
            $existingSubscription = $this->account->subscriptions()
                ->where('event_group', $eventGroup)
                ->where('is_active', true)
                ->first();
        }

        $url = $existingSubscription
            ? "{$baseUrl}/c/{$companyId}/subscriptions/{$existingSubscription->fic_subscription_id}"
            : "{$baseUrl}/c/{$companyId}/subscriptions";

        $method = $existingSubscription ? 'PUT' : 'POST';

        // Use the new API format with 'sink' and 'types' array
        $payload = [
            'data' => [
                'sink' => $webhookUrl,
                'types' => $eventTypesArray,
                'verification_method' => 'header',
                'config' => [
                    'mapping' => 'binary',
                ],
            ],
        ];

        $response = $this->httpClient->request($method, $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $responseData = json_decode($response->getBody()->getContents(), true);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                "FIC API returned HTTP {$statusCode}: " . ($responseData['error'] ?? json_encode($responseData)),
                $statusCode
            );
        }

        // Extract subscription data from response
        $data = $responseData['data'] ?? $responseData;

        return [
            'id' => $data['id'] ?? $existingSubscription->fic_subscription_id ?? null,
            'secret' => $data['secret'] ?? $data['verification_token'] ?? null,
            'expires_at' => isset($data['expires_at'])
                ? new \Carbon\Carbon($data['expires_at'])
                : null,
        ];
    }
}