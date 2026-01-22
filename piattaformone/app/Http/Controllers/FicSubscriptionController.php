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
     *
     * @param Request $request
     * @return JsonResponse
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
        if (!str_contains($sink, $expectedUrlPattern)) {
            return response()->json([
                'success' => false,
                'error' => 'Webhook URL mismatch',
                'message' => "The webhook URL must match the event group. Expected URL to contain: {$expectedUrlPattern}, but got: {$sink}",
                'expected_event_group' => $eventGroup,
                'extracted_from_types' => array_map(fn($type) => $this->extractEventGroup($type), $types),
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

            // Check if subscription already exists in database
            $existingSubscription = FicSubscription::where('fic_account_id', $accountId)
                ->where('event_group', $eventGroup)
                ->where('is_active', true)
                ->first();

            if ($existingSubscription) {
                // Update existing subscription
                $existingSubscription->update([
                    'fic_subscription_id' => $ficSubscriptionId,
                    'webhook_secret' => null, // SDK doesn't return secret in response
                    'expires_at' => null, // SDK doesn't return expires_at in response
                    'is_active' => true,
                ]);
                $subscription = $existingSubscription;
            } else {
                // Create new subscription
                $subscription = FicSubscription::create([
                    'fic_account_id' => $accountId,
                    'fic_subscription_id' => $ficSubscriptionId,
                    'event_group' => $eventGroup,
                    'webhook_secret' => null,
                    'expires_at' => null,
                    'is_active' => true,
                ]);
            }

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
     *
     * @param string $eventType
     * @return string
     */
    private function extractEventGroup(string $eventType): string
    {
        if (!str_contains($eventType, '.')) {
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
     *
     * @return JsonResponse
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
}
