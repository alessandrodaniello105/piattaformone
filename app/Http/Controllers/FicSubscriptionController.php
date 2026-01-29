<?php

namespace App\Http\Controllers;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use FattureInCloud\Api\WebhooksApi;
use FattureInCloud\Configuration;
use FattureInCloud\Model\CreateWebhooksSubscriptionRequest;
use FattureInCloud\Model\WebhooksSubscription;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FicSubscriptionController extends Controller
{
    /**
     * Create a new webhook subscription using the Fatture in Cloud PHP SDK.
     *
     * Note: According to FIC documentation, if Group Types are used when creating
     * a subscription, they will be converted to Event Types. GET requests will
     * always return Event Types, not the original Group Types. Therefore, we
     * validate that the webhook URL matches the event_group extracted from the
     * Event Types provided, ensuring consistency.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'account_id' => 'required|integer|exists:fic_accounts,id',
                'sink' => 'required|url|starts_with:https://',
                'types' => 'required|array|min:1',
                'types.*' => 'required|string',
                'verification_method' => 'nullable|string|in:header,query',
                'event_group' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $accountId = $validated['account_id'];
        $sink = $validated['sink'];
        $types = $validated['types'];
        $verificationMethod = $validated['verification_method'] ?? 'header';
        $eventGroup = $validated['event_group'] ?? $this->extractEventGroup($types[0]);

        // Validate that the sink URL matches the event_group
        $expectedUrlPattern = "/api/webhooks/fic/{$accountId}/{$eventGroup}";
        if (! str_contains($sink, $expectedUrlPattern)) {
            return response()->json([
                'success' => false,
                'error' => 'Webhook URL mismatch',
                'message' => "The webhook URL must match the event group. Expected URL to contain: {$expectedUrlPattern}, but got: {$sink}",
                'expected_event_group' => $eventGroup,
                'extracted_from_types' => array_map(fn ($type) => $this->extractEventGroup($type), $types),
            ], 422);
        }

        try {
            // Find the account
            $account = FicAccount::findOrFail($accountId);

            // Check if account has access token
            if (empty($account->access_token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account has no access token. Please connect the account first.',
                ], 400);
            }

            // Initialize SDK configuration
            $config = Configuration::getDefaultConfiguration();
            $config->setAccessToken($account->access_token);

            // Create HTTP client
            $httpClient = new Client([
                'timeout' => 30.0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Initialize WebhooksApi
            $webhooksApi = new WebhooksApi($httpClient, $config);

            // Create subscription request
            // Build the data array according to SDK structure
            $subscriptionDataArray = [
                'sink' => $sink,
                'types' => $types,
                'verification_method' => $verificationMethod,
                'config' => [
                    'mapping' => 'binary',
                ],
            ];

            // Create WebhooksSubscription object
            $subscriptionData = new WebhooksSubscription($subscriptionDataArray);

            // Create request with data
            $subscriptionRequest = new CreateWebhooksSubscriptionRequest([
                'data' => $subscriptionData,
            ]);

            // Call the API
            $result = $webhooksApi->createWebhooksSubscription(
                $account->company_id,
                $subscriptionRequest
            );

            // Extract subscription data from response
            $subscriptionResponse = $result->getData();

            $ficSubscriptionId = $subscriptionResponse->getId();
            $verified = $subscriptionResponse->getVerified() ?? false;

            // Use fic_subscription_id as the ONLY unique key
            // This allows multiple subscriptions with the same event_group
            // (e.g., separate subscriptions for clients and suppliers both in 'entity' group)
            $subscription = FicSubscription::updateOrCreate(
                [
                    'fic_subscription_id' => $ficSubscriptionId,
                ],
                [
                    'fic_account_id' => $accountId,
                    'event_group' => $eventGroup,
                    'webhook_secret' => null, // SDK doesn't return secret in response
                    'expires_at' => null, // SDK doesn't return expires_at in response
                    'is_active' => true,
                ]
            );

            Log::info('FIC Subscription created via SDK', [
                'subscription_id' => $subscription->id,
                'fic_subscription_id' => $ficSubscriptionId,
                'account_id' => $accountId,
                'event_group' => $eventGroup,
                'types' => $types,
                'verified' => $verified,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription created successfully',
                'data' => [
                    'subscription' => [
                        'id' => $subscription->id,
                        'fic_subscription_id' => $ficSubscriptionId,
                        'event_group' => $eventGroup,
                        'sink' => $sink,
                        'types' => $types,
                        'verified' => $verified,
                        'is_active' => $subscription->is_active,
                    ],
                ],
            ], 201);

        } catch (\FattureInCloud\ApiException $e) {
            $statusCode = $e->getCode();
            $responseBody = $e->getResponseBody() ?? '';

            Log::error('FIC SDK API Exception when creating subscription', [
                'account_id' => $accountId,
                'status_code' => $statusCode,
                'response' => $responseBody,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = 'Failed to create subscription';
            if ($statusCode === 401) {
                $errorMessage = 'Authentication failed. Access token may be expired.';
            } elseif ($statusCode === 429) {
                $errorMessage = 'Rate limit exceeded. Please try again later.';
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'details' => $e->getMessage(),
            ], $statusCode ?: 500);

        } catch (\Exception $e) {
            Log::error('FIC Subscription creation error', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Unexpected error occurred',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract event group from event type.
     */
    private function extractEventGroup(string $eventType): string
    {
        if (! str_contains($eventType, '.')) {
            return $eventType;
        }

        $parts = explode('.', $eventType);

        // Look for common patterns
        if (in_array('entities', $parts)) {
            return 'entity';
        } elseif (in_array('issued_documents', $parts)) {
            return 'issued_documents';
        } elseif (in_array('received_documents', $parts)) {
            return 'received_documents';
        } elseif (in_array('products', $parts)) {
            return 'products';
        } elseif (in_array('receipts', $parts)) {
            return 'receipts';
        }

        // Default: use the first meaningful part after 'webhooks'
        $webhookIndex = array_search('webhooks', $parts);
        if ($webhookIndex !== false && isset($parts[$webhookIndex + 1])) {
            return $parts[$webhookIndex + 1];
        }

        return 'default';
    }

    /**
     * Get list of available FIC accounts.
     */
    public function accounts(): JsonResponse
    {
        $accounts = FicAccount::whereNotNull('access_token')
            ->select('id', 'name', 'company_id', 'company_name', 'status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * List webhook subscriptions using the Fatture in Cloud PHP SDK.
     *
     * Fetches all active webhook subscriptions for the specified account
     * using the WebhooksApi::listWebhooksSubscriptions() method.
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'account_id' => 'required|integer|exists:fic_accounts,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $accountId = $validated['account_id'];

        try {
            // Find the account
            $account = FicAccount::findOrFail($accountId);

            // Check if account has access token
            if (empty($account->access_token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account has no access token. Please connect the account first.',
                ], 400);
            }

            // Initialize SDK configuration
            $config = Configuration::getDefaultConfiguration();
            $config->setAccessToken($account->access_token);

            // Create HTTP client
            $httpClient = new Client([
                'timeout' => 30.0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Initialize WebhooksApi
            $webhooksApi = new WebhooksApi($httpClient, $config);

            // List subscriptions using SDK
            $result = $webhooksApi->listWebhooksSubscriptions($account->company_id);

            // Extract subscriptions data from response
            $subscriptionsData = $result->getData();
            $subscriptions = [];

            if ($subscriptionsData !== null) {
                // Handle both single object and array
                $subscriptionsArray = is_array($subscriptionsData) ? $subscriptionsData : [$subscriptionsData];

                foreach ($subscriptionsArray as $subscription) {
                    // Extract types - handle both array of strings and array of EventType objects
                    $types = [];
                    if (method_exists($subscription, 'getTypes')) {
                        $typesData = $subscription->getTypes();
                        if (is_array($typesData)) {
                            foreach ($typesData as $type) {
                                // If it's a string, use it directly; if it's an object, try to get its value
                                if (is_string($type)) {
                                    $types[] = $type;
                                } elseif (is_object($type) && method_exists($type, 'getValue')) {
                                    $types[] = $type->getValue();
                                } elseif (is_object($type) && method_exists($type, '__toString')) {
                                    $types[] = (string) $type;
                                } else {
                                    $types[] = json_encode($type);
                                }
                            }
                        }
                    }

                    // Extract config
                    $config = null;
                    if (method_exists($subscription, 'getConfig')) {
                        $configData = $subscription->getConfig();
                        if ($configData !== null) {
                            if (is_object($configData) && method_exists($configData, 'toArray')) {
                                $config = $configData->toArray();
                            } elseif (is_array($configData)) {
                                $config = $configData;
                            }
                        }
                    }

                    // Extract data using getter methods
                    $subscriptions[] = [
                        'id' => method_exists($subscription, 'getId') ? $subscription->getId() : null,
                        'sink' => method_exists($subscription, 'getSink') ? $subscription->getSink() : null,
                        'verified' => method_exists($subscription, 'getVerified') ? ($subscription->getVerified() ?? false) : false,
                        'types' => $types,
                        'config' => $config,
                    ];
                }
            }

            Log::info('FIC Subscriptions listed via SDK', [
                'account_id' => $accountId,
                'count' => count($subscriptions),
            ]);

            return response()->json([
                'success' => true,
                'data' => $subscriptions,
            ]);

        } catch (\FattureInCloud\ApiException $e) {
            $statusCode = $e->getCode();
            $responseBody = $e->getResponseBody() ?? '';

            Log::error('FIC SDK API Exception when listing subscriptions', [
                'account_id' => $accountId,
                'status_code' => $statusCode,
                'response' => $responseBody,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = 'Failed to list subscriptions';
            if ($statusCode === 401) {
                $errorMessage = 'Authentication failed. Access token may be expired.';
            } elseif ($statusCode === 429) {
                $errorMessage = 'Rate limit exceeded. Please try again later.';
            }

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'details' => $e->getMessage(),
            ], $statusCode ?: 500);

        } catch (\Exception $e) {
            Log::error('FIC Subscription listing error', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Unexpected error occurred',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
