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
     * @param Client|null $httpClient Optional HTTP client (useful for testing with mocks)
     */
    public function __construct(FicAccount $account, ?Client $httpClient = null)
    {
        $this->account = $account;
        $this->httpClient = $httpClient;
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

        // Create HTTP client only if not already provided (e.g., for testing)
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'timeout' => 30.0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

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

    /**
     * Fetch client details by ID from FIC API.
     *
     * @param int $clientId The FIC client ID
     * @return array Normalized client data with keys: id, name, code, fic_created_at, fic_updated_at, raw
     * @throws \Exception If the API call fails
     */
    public function fetchClientById(int $clientId): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/entities/clients/{$clientId}";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching client {$clientId}: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            $data = $responseData['data'] ?? $responseData;

            // Normalize the response to match our database structure
            return [
                'id' => $data['id'] ?? $clientId,
                'name' => $data['name'] ?? null,
                'code' => $data['code'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'fic_created_at' => isset($data['created_at']) ? new \Carbon\Carbon($data['created_at']) : null,
                'fic_updated_at' => isset($data['updated_at']) ? new \Carbon\Carbon($data['updated_at']) : null,
                'raw' => $data,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching client', [
                'account_id' => $this->account->id,
                'client_id' => $clientId,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch client {$clientId} from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching client', [
                'account_id' => $this->account->id,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch supplier details by ID from FIC API.
     *
     * @param int $supplierId The FIC supplier ID
     * @return array Normalized supplier data with keys: id, name, code, fic_created_at, fic_updated_at, raw
     * @throws \Exception If the API call fails
     */
    public function fetchSupplierById(int $supplierId): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/entities/suppliers/{$supplierId}";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching supplier {$supplierId}: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            $data = $responseData['data'] ?? $responseData;

            // Normalize the response to match our database structure
            return [
                'id' => $data['id'] ?? $supplierId,
                'name' => $data['name'] ?? null,
                'code' => $data['code'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'fic_created_at' => isset($data['created_at']) ? new \Carbon\Carbon($data['created_at']) : null,
                'fic_updated_at' => isset($data['updated_at']) ? new \Carbon\Carbon($data['updated_at']) : null,
                'raw' => $data,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching supplier', [
                'account_id' => $this->account->id,
                'supplier_id' => $supplierId,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch supplier {$supplierId} from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching supplier', [
                'account_id' => $this->account->id,
                'supplier_id' => $supplierId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch issued quote details by ID from FIC API.
     *
     * @param int $quoteId The FIC quote ID
     * @return array Normalized quote data with keys: id, number, status, total_gross, fic_date, fic_created_at, raw
     * @throws \Exception If the API call fails
     */
    public function fetchIssuedQuoteById(int $quoteId): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/issued_documents/quotes/{$quoteId}";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching quote {$quoteId}: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            $data = $responseData['data'] ?? $responseData;

            // Normalize the response to match our database structure
            return [
                'id' => $data['id'] ?? $quoteId,
                'number' => $data['number'] ?? null,
                'status' => $data['status'] ?? null,
                'total_gross' => $data['amount_net'] ?? $data['total'] ?? $data['total_gross'] ?? null,
                'fic_date' => isset($data['date']) ? new \Carbon\Carbon($data['date']) : null,
                'fic_created_at' => isset($data['created_at']) ? new \Carbon\Carbon($data['created_at']) : null,
                'raw' => $data,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching quote', [
                'account_id' => $this->account->id,
                'quote_id' => $quoteId,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch quote {$quoteId} from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching quote', [
                'account_id' => $this->account->id,
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch issued invoice details by ID from FIC API.
     *
     * @param int $invoiceId The FIC invoice ID
     * @return array Normalized invoice data with keys: id, number, status, total_gross, fic_date, fic_created_at, raw
     * @throws \Exception If the API call fails
     */
    public function fetchIssuedInvoiceById(int $invoiceId): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/issued_documents/invoices/{$invoiceId}";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching invoice {$invoiceId}: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            $data = $responseData['data'] ?? $responseData;

            // Normalize the response to match our database structure
            return [
                'id' => $data['id'] ?? $invoiceId,
                'number' => $data['number'] ?? null,
                'status' => $data['status'] ?? null,
                'total_gross' => $data['amount_net'] ?? $data['total'] ?? $data['total_gross'] ?? null,
                'fic_date' => isset($data['date']) ? new \Carbon\Carbon($data['date']) : null,
                'fic_created_at' => isset($data['created_at']) ? new \Carbon\Carbon($data['created_at']) : null,
                'raw' => $data,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching invoice', [
                'account_id' => $this->account->id,
                'invoice_id' => $invoiceId,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch invoice {$invoiceId} from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching invoice', [
                'account_id' => $this->account->id,
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch list of suppliers from FIC API.
     *
     * Uses SDK SuppliersApi::listSuppliers() when available (reference implementation).
     * Falls back to direct HTTP calls if SDK is not available.
     *
     * @param array $filters Optional filters (e.g., ['page' => 1, 'per_page' => 50, 'fields' => '...', 'fieldset' => '...', 'sort' => '...', 'q' => '...'])
     * @return array List of suppliers with pagination info
     * @throws \Exception If the API call fails
     */
    public function fetchSuppliersList(array $filters = []): array
    {
        $this->initializeSdk();

        // Try to use SuppliersApi if available
        if (class_exists(\FattureInCloud\Api\SuppliersApi::class)) {
            return $this->fetchSuppliersListViaApi($filters);
        }

        // Fallback to direct HTTP call
        return $this->fetchSuppliersListViaHttp($filters);
    }

    /**
     * Fetch suppliers list using SuppliersApi (SDK method).
     *
     * @param array $filters
     * @return array
     */
    private function fetchSuppliersListViaApi(array $filters = []): array
    {
        $suppliersApi = new \FattureInCloud\Api\SuppliersApi($this->httpClient, $this->config);

        $companyId = $this->account->company_id;
        $fields = $filters['fields'] ?? null;
        $fieldset = $filters['fieldset'] ?? null;
        $sort = $filters['sort'] ?? null;
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 50;
        $q = $filters['q'] ?? null;

        try {
            $response = $suppliersApi->listSuppliers(
                $companyId,
                $fields,
                $fieldset,
                $sort,
                $page,
                $perPage,
                $q
            );

            // Extract data from Response object
            // ListSuppliersResponse has a 'data' property which is an array of Supplier objects
            $suppliers = [];
            $supplierData = $response->getData();

            if ($supplierData !== null) {
                if (is_array($supplierData)) {
                    // If data is directly an array of Supplier objects
                    foreach ($supplierData as $supplier) {
                        $suppliers[] = $this->normalizeSupplierFromModel($supplier);
                    }
                } else {
                    // If data is a single Supplier object
                    $suppliers[] = $this->normalizeSupplierFromModel($supplierData);
                }
            }

            // Build response array matching HTTP response format
            return [
                'data' => $suppliers,
                'current_page' => method_exists($response, 'getCurrentPage') ? $response->getCurrentPage() : $page,
                'per_page' => method_exists($response, 'getPerPage') ? $response->getPerPage() : $perPage,
                'total' => method_exists($response, 'getTotal') ? $response->getTotal() : count($suppliers),
                'last_page' => method_exists($response, 'getLastPage') ? $response->getLastPage() : 1,
                'from' => method_exists($response, 'getFrom') ? $response->getFrom() : (($page - 1) * $perPage + 1),
                'to' => method_exists($response, 'getTo') ? $response->getTo() : min($page * $perPage, method_exists($response, 'getTotal') ? $response->getTotal() : count($suppliers)),
                'first_page_url' => method_exists($response, 'getFirstPageUrl') ? $response->getFirstPageUrl() : null,
                'last_page_url' => method_exists($response, 'getLastPageUrl') ? $response->getLastPageUrl() : null,
                'next_page_url' => method_exists($response, 'getNextPageUrl') ? $response->getNextPageUrl() : null,
                'prev_page_url' => method_exists($response, 'getPrevPageUrl') ? $response->getPrevPageUrl() : null,
                'path' => method_exists($response, 'getPath') ? $response->getPath() : null,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching suppliers list via SDK', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch suppliers list from FIC API via SDK (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching suppliers list via SDK', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch suppliers list using direct HTTP calls (fallback method).
     *
     * @param array $filters
     * @return array
     */
    private function fetchSuppliersListViaHttp(array $filters = []): array
    {
        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/entities/suppliers";
        
        $queryParams = array_merge([
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ], $filters);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching suppliers list: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? $responseData;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching suppliers list', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch suppliers list from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching suppliers list', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Normalize a Supplier Model object to array format matching database structure.
     *
     * @param mixed $supplier Supplier Model object from SDK
     * @return array Normalized supplier data
     */
    private function normalizeSupplierFromModel($supplier): array
    {
        // Extract data using getter methods if available, otherwise convert to array
        $data = [];
        
        if (method_exists($supplier, 'toArray')) {
            $data = $supplier->toArray();
        } elseif (method_exists($supplier, 'jsonSerialize')) {
            $data = $supplier->jsonSerialize();
        } else {
            // Try to get individual properties via getters
            $data = [
                'id' => method_exists($supplier, 'getId') ? $supplier->getId() : null,
                'name' => method_exists($supplier, 'getName') ? $supplier->getName() : null,
                'code' => method_exists($supplier, 'getCode') ? $supplier->getCode() : null,
                'created_at' => method_exists($supplier, 'getCreatedAt') ? $supplier->getCreatedAt() : null,
                'updated_at' => method_exists($supplier, 'getUpdatedAt') ? $supplier->getUpdatedAt() : null,
            ];
        }

        // Ensure we have the full raw data (convert object to array if needed)
        $raw = is_array($data) ? $data : json_decode(json_encode($data), true);

        // Normalize to match database structure
        return [
            'id' => $raw['id'] ?? null,
            'name' => $raw['name'] ?? null,
            'code' => $raw['code'] ?? null,
            'vat_number' => $raw['vat_number'] ?? null,
            'fic_created_at' => isset($raw['created_at']) ? new \Carbon\Carbon($raw['created_at']) : null,
            'fic_updated_at' => isset($raw['updated_at']) ? new \Carbon\Carbon($raw['updated_at']) : null,
            'raw' => $raw,
        ];
    }

    /**
     * Fetch list of clients from FIC API.
     *
     * @param array $filters Optional filters (e.g., ['page' => 1, 'per_page' => 50])
     * @return array List of clients with pagination info
     * @throws \Exception If the API call fails
     */
    public function fetchClientsList(array $filters = []): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/entities/clients";
        
        $queryParams = array_merge([
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ], $filters);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching clients list: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? $responseData;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching clients list', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch clients list from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching clients list', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch list of issued quotes from FIC API.
     *
     * @param array $filters Optional filters (e.g., ['page' => 1, 'per_page' => 50])
     * @return array List of quotes with pagination info
     * @throws \Exception If the API call fails
     */
    public function fetchQuotesList(array $filters = []): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/issued_documents/quotes";
        
        $queryParams = array_merge([
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ], $filters);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching quotes list: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? $responseData;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching quotes list', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch quotes list from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching quotes list', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch list of issued invoices from FIC API.
     *
     * @param array $filters Optional filters (e.g., ['page' => 1, 'per_page' => 50])
     * @return array List of invoices with pagination info
     * @throws \Exception If the API call fails
     */
    public function fetchInvoicesList(array $filters = []): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/issued_documents/invoices";
        
        $queryParams = array_merge([
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ], $filters);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching invoices list: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? $responseData;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching invoices list', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch invoices list from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching invoices list', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch list of webhook subscriptions from FIC API.
     *
     * @return array List of subscriptions with their details
     * @throws \Exception If the API call fails
     */
    public function fetchSubscriptions(): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/subscriptions";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching subscriptions: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching subscriptions', [
                'account_id' => $this->account->id,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch subscriptions from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching subscriptions', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get a specific subscription by ID from FIC API.
     *
     * @param string $subscriptionId The FIC subscription ID
     * @return array Subscription details
     * @throws \Exception If the API call fails
     */
    public function getSubscription(string $subscriptionId): array
    {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/subscriptions/{$subscriptionId}";

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(
                    "FIC API returned HTTP {$statusCode} when fetching subscription {$subscriptionId}: " . 
                    ($responseData['error']['message'] ?? json_encode($responseData)),
                    $statusCode
                );
            }

            return $responseData['data'] ?? $responseData;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
            $responseBody = $e->getResponse()?->getBody()?->getContents() ?? '';

            Log::error('FIC API: Error fetching subscription', [
                'account_id' => $this->account->id,
                'subscription_id' => $subscriptionId,
                'status_code' => $statusCode,
                'response' => $responseBody,
            ]);

            throw new \RuntimeException(
                "Failed to fetch subscription {$subscriptionId} from FIC API (HTTP {$statusCode})",
                $statusCode,
                $e
            );
        } catch (\Exception $e) {
            Log::error('FIC API: Unexpected error fetching subscription', [
                'account_id' => $this->account->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update an existing subscription by FIC subscription ID.
     *
     * @param string $ficSubscriptionId The FIC subscription ID (e.g., 'SUB3098')
     * @param string $webhookUrl The new webhook URL (sink)
     * @param array|null $eventTypes Optional: new event types (if null, keeps existing)
     * @return array Subscription data with keys: id, verified, types, sink
     * @throws \Exception If the API call fails
     */
    public function updateSubscriptionById(
        string $ficSubscriptionId,
        string $webhookUrl,
        ?array $eventTypes = null
    ): array {
        $this->initializeSdk();

        $baseUrl = 'https://api-v2.fattureincloud.it';
        $companyId = $this->account->company_id;
        $accessToken = $this->account->access_token;

        $url = "{$baseUrl}/c/{$companyId}/subscriptions/{$ficSubscriptionId}";

        // First, get current subscription to preserve event types
        $currentSubscription = null;
        try {
            $getResponse = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/json',
                ],
            ]);
            $currentData = json_decode($getResponse->getBody()->getContents(), true);
            $currentSubscription = $currentData['data'] ?? null;
            
            if (!$currentSubscription) {
                throw new \RuntimeException("Could not fetch current subscription data");
            }
        } catch (\Exception $e) {
            Log::error('FIC API: Could not fetch current subscription before update', [
                'subscription_id' => $ficSubscriptionId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to fetch current subscription: " . $e->getMessage());
        }

        // Use provided event types or keep existing ones exactly as they are
        $typesToUse = $eventTypes ?? ($currentSubscription['types'] ?? []);
        
        // Build payload - only include fields we want to update
        // According to FIC docs, when updating sink, we should include existing types
        $payload = [
            'data' => [
                'sink' => $webhookUrl,
                'types' => $typesToUse, // Must include existing types when updating sink
                'verification_method' => $currentSubscription['verification_method'] ?? 'header',
                'config' => $currentSubscription['config'] ?? [
                    'mapping' => 'binary',
                ],
            ],
        ];

        Log::info('FIC API: Updating subscription', [
            'subscription_id' => $ficSubscriptionId,
            'new_sink' => $webhookUrl,
            'event_types_count' => count($typesToUse),
            'event_types' => $typesToUse,
        ]);

        $response = $this->httpClient->request('PUT', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $responseData = json_decode($response->getBody()->getContents(), true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMessage = $responseData['error']['message'] ?? json_encode($responseData);
            Log::error('FIC API: Subscription update failed', [
                'subscription_id' => $ficSubscriptionId,
                'status_code' => $statusCode,
                'error' => $errorMessage,
                'payload' => $payload,
            ]);
            throw new \RuntimeException(
                "FIC API returned HTTP {$statusCode}: {$errorMessage}",
                $statusCode
            );
        }

        $data = $responseData['data'] ?? $responseData;

        Log::info('FIC API: Subscription updated successfully', [
            'account_id' => $this->account->id,
            'subscription_id' => $ficSubscriptionId,
            'new_url' => $webhookUrl,
            'verified' => $data['verified'] ?? false,
        ]);

        return $data;
    }

}

