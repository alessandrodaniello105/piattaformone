<?php

namespace App\Http\Controllers;

use App\Events\WebhookReceived;
use App\Jobs\ProcessFicWebhook;
use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for handling Fatture in Cloud webhook requests.
 *
 * Supports dynamic routes with account_id and event_group parameters.
 * Handles CloudEvents format with headers (ce-type, ce-time, ce-subject, etc.)
 * and optionally validates JWT signatures from Authorization header.
 *
 * GET requests are for subscription verification (challenge/response).
 * POST requests are for webhook notifications in CloudEvents format.
 */
class FicWebhookController extends Controller
{
    /**
     * Handle webhook requests (GET and POST).
     *
     * GET requests are for subscription verification (challenge/response).
     * POST requests are for webhook notifications with signature validation.
     *
     * Route: /api/webhooks/fic/{account_id}/{group}
     *
     * @param Request $request
     * @param int $accountId The FIC account ID
     * @param string $group The event group (e.g., 'entity', 'issued_documents')
     * @return JsonResponse
     */
    public function handle(Request $request, int $accountId, string $group): JsonResponse
    {
        // Handle subscription verification (GET request with challenge)
        if ($request->isMethod('GET')) {
            return $this->handleSubscriptionVerification($request, $accountId, $group);
        }

        // Handle webhook notification (POST request with signature)
        if ($request->isMethod('POST')) {
            return $this->handleWebhookNotification($request, $accountId, $group);
        }

        // Method not allowed
        return response()->json(['error' => 'Method not allowed'], 405);
    }

    /**
     * Handle subscription verification (GET request).
     *
     * Fatture in Cloud sends a GET request with a challenge token
     * in the header or query parameter. We must respond with
     * the same challenge token to verify the subscription.
     *
     * @param Request $request
     * @param int $accountId
     * @param string $group
     * @return JsonResponse
     */
    private function handleSubscriptionVerification(
        Request $request,
        int $accountId,
        string $group
    ): JsonResponse {
        // Get challenge from header (case-insensitive) or query parameter
        $challenge = null;
        
        // Try different variations of the header name (case-insensitive)
        $headerNames = [
            'x-fic-verification-challenge',
            'X-Fic-Verification-Challenge',
            'X-FIC-Verification-Challenge',
        ];
        
        foreach ($headerNames as $headerName) {
            $challenge = $request->header($headerName);
            if ($challenge) {
                break;
            }
        }
        
        // Also check all headers for case-insensitive match
        if (!$challenge) {
            $allHeaders = $request->headers->all();
            foreach ($allHeaders as $key => $value) {
                if (strtolower($key) === 'x-fic-verification-challenge') {
                    $challenge = is_array($value) ? $value[0] : $value;
                    break;
                }
            }
        }
        
        // Fallback to query parameter
        if (!$challenge) {
            $challenge = $request->query('x-fic-verification-challenge');
        }
        
        // Log all headers for debugging
        Log::info('FIC Webhook: Verification request received', [
            'account_id' => $accountId,
            'event_group' => $group,
            'method' => $request->method(),
            'all_headers' => $request->headers->all(),
            'query_params' => $request->query->all(),
            'challenge_found' => !empty($challenge),
        ]);

        if (!$challenge) {
            Log::warning('FIC Webhook: Subscription verification request without challenge', [
                'account_id' => $accountId,
                'event_group' => $group,
                'headers' => $request->headers->all(),
                'query' => $request->query->all(),
            ]);

            return response()->json([
                'error' => 'Missing verification challenge'
            ], 400);
        }

        Log::info('FIC Webhook: Subscription verification request received', [
            'account_id' => $accountId,
            'event_group' => $group,
            'challenge' => $challenge,
            'ip' => $request->ip(),
        ]);

        // Return the challenge token as required by Fatture in Cloud
        return response()->json([
            'verification' => $challenge
        ], 200);
    }

    /**
     * Handle webhook notification (POST request with CloudEvents format).
     *
     * Supports both Binary and Structured CloudEvents content modes:
     * - Binary: CloudEvents attributes in HTTP headers (ce-type, ce-time, etc.)
     * - Structured: CloudEvents attributes in request body (Content-Type: application/cloudevents+json)
     *
     * Validates JWT signature from Authorization header and normalizes the payload
     * for ProcessFicWebhook job.
     *
     * @param Request $request
     * @param int $accountId
     * @param string $group
     * @return JsonResponse
     */
    private function handleWebhookNotification(
        Request $request,
        int $accountId,
        string $group
    ): JsonResponse {
        // Retrieve the subscription
        $subscription = FicSubscription::where('fic_account_id', $accountId)
            ->where('event_group', $group)
            ->where('is_active', true)
            ->first();

        if (!$subscription) {
            // Log all request details for debugging
            Log::warning('FIC Webhook: Subscription not found in database', [
                'account_id' => $accountId,
                'event_group' => $group,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'method' => $request->method(),
                'headers' => $request->headers->all(),
                'body' => $request->all(),
                'note' => 'This webhook was received but subscription not found in local database. Check FIC API for actual subscription status.',
            ]);

            // Still return 404, but log extensively for debugging
            return response()->json([
                'error' => 'Subscription not found or inactive'
            ], 404);
        }

        // Determine content mode: Binary (headers) or Structured (body)
        $contentType = $request->header('Content-Type', '');
        $isStructuredMode = str_contains($contentType, 'application/cloudevents+json');
        
        // Parse request body
        $body = $request->all();

        // Extract CloudEvents attributes based on content mode
        if ($isStructuredMode) {
            // Structured mode: all attributes in body
            $ceType = $body['type'] ?? null;
            $ceTime = $body['time'] ?? null;
            $ceSubject = $body['subject'] ?? null;
            $ceId = $body['id'] ?? null;
            $ceSource = $body['source'] ?? null;
            $ceSpecVersion = $body['specversion'] ?? null;
            $ids = $body['data']['ids'] ?? [];
        } else {
            // Binary mode: attributes in headers
            $ceType = $request->header('ce-type');
            $ceTime = $request->header('ce-time');
            $ceSubject = $request->header('ce-subject');
            $ceId = $request->header('ce-id');
            $ceSource = $request->header('ce-source');
            $ceSpecVersion = $request->header('ce-specversion');
            $ids = $body['data']['ids'] ?? [];
        }

        // Log structured information about headers and request
        Log::info('FIC Webhook: CloudEvents notification received', [
            'account_id' => $accountId,
            'event_group' => $group,
            'content_mode' => $isStructuredMode ? 'structured' : 'binary',
            'content_type' => $contentType,
            'ce_type' => $ceType,
            'ce_time' => $ceTime,
            'ce_subject' => $ceSubject,
            'ce_id' => $ceId,
            'ce_source' => $ceSource,
            'ce_specversion' => $ceSpecVersion,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Optionally verify JWT token from Authorization header
        $verifyJwt = config('fattureincloud.webhook_verify_jwt', true);
        if ($verifyJwt) {
            $authHeader = $request->header('Authorization');
            
            if (!$authHeader) {
                Log::warning('FIC Webhook: Missing Authorization header', [
                    'account_id' => $accountId,
                    'event_group' => $group,
                ]);

                return response()->json([
                    'error' => 'Missing Authorization header'
                ], 401);
            }

            // Extract token from "Bearer SIGNATURE" format
            if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                Log::warning('FIC Webhook: Invalid Authorization header format', [
                    'account_id' => $accountId,
                    'event_group' => $group,
                    'header' => $authHeader,
                ]);

                return response()->json([
                    'error' => 'Invalid Authorization header format'
                ], 401);
            }

            $jwtToken = $matches[1];

            if (!$this->verifyJwtToken($jwtToken, $ceId, $ceSubject)) {
                Log::warning('FIC Webhook: Invalid JWT token', [
                    'account_id' => $accountId,
                    'event_group' => $group,
                    'ce_id' => $ceId,
                ]);

                return response()->json([
                    'error' => 'Invalid JWT token'
                ], 401);
            }

            Log::debug('FIC Webhook: JWT token verified successfully', [
                'account_id' => $accountId,
                'event_group' => $group,
            ]);
        } else {
            Log::debug('FIC Webhook: JWT verification disabled', [
                'account_id' => $accountId,
                'event_group' => $group,
            ]);
        }

        // Validate required CloudEvents attributes
        if (empty($ceType)) {
            Log::warning('FIC Webhook: Missing ce-type attribute', [
                'account_id' => $accountId,
                'event_group' => $group,
                'content_mode' => $isStructuredMode ? 'structured' : 'binary',
            ]);

            return response()->json([
                'error' => 'Missing required CloudEvents type attribute'
            ], 400);
        }

        // Log structured information about IDs received
        Log::info('FIC Webhook: Event data received', [
            'account_id' => $accountId,
            'event_group' => $group,
            'ce_type' => $ceType,
            'ids' => $ids,
            'ids_count' => count($ids),
        ]);

        if (empty($ids)) {
            Log::warning('FIC Webhook: Empty IDs array in payload', [
                'account_id' => $accountId,
                'event_group' => $group,
                'ce_type' => $ceType,
                'body' => $body,
            ]);

            return response()->json([
                'error' => 'Empty IDs array in payload'
            ], 400);
        }

        // Normalize payload for ProcessFicWebhook job
        // Structure: event, occurred_at, subject, data.ids
        $normalizedPayload = [
            'event' => $ceType,
            'occurred_at' => $ceTime,
            'subject' => $ceSubject,
            'ce_id' => $ceId,
            'ce_source' => $ceSource,
            'ce_specversion' => $ceSpecVersion,
            'data' => [
                'ids' => $ids,
            ],
        ];

        // Dispatch job for async processing
        try {
            ProcessFicWebhook::dispatch($normalizedPayload, $accountId, $group)
                ->onConnection('redis');

            // Broadcast event for real-time monitoring
            try {
                // Try to fetch basic object details for display
                $objectDetails = $this->fetchObjectDetailsForEvent($accountId, $ceType, $ids);
                
                $event = new WebhookReceived(
                    accountId: $accountId,
                    eventGroup: $group,
                    eventType: $ceType,
                    data: $normalizedPayload,
                    ceId: $ceId,
                    ceTime: $ceTime,
                    ceSubject: $ceSubject,
                    objectDetails: $objectDetails,
                );
                
                // Broadcast using Laravel's event system
                // This will automatically use the configured broadcaster (Reverb)
                broadcast($event);
                
                Log::info('FIC Webhook: Event broadcasted successfully', [
                    'account_id' => $accountId,
                    'event_group' => $group,
                    'event' => $ceType,
                    'channels' => array_map(fn($ch) => $ch->name, $event->broadcastOn()),
                ]);
            } catch (\Exception $broadcastException) {
                // Log broadcast error but don't fail the webhook processing
                Log::warning('FIC Webhook: Failed to broadcast event', [
                    'account_id' => $accountId,
                    'event_group' => $group,
                    'error' => $broadcastException->getMessage(),
                    'trace' => $broadcastException->getTraceAsString(),
                ]);
            }

            Log::info('FIC Webhook: Notification queued for processing', [
                'account_id' => $accountId,
                'event_group' => $group,
                'event' => $ceType,
                'ids_count' => count($ids),
            ]);

            return response()->json([
                'status' => 'accepted',
                'message' => 'Webhook queued for processing'
            ], 202);
        } catch (\Exception $e) {
            Log::error('FIC Webhook: Failed to queue webhook job', [
                'account_id' => $accountId,
                'event_group' => $group,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to queue webhook'
            ], 500);
        }
    }

    /**
     * Verify HMAC-SHA256 signature.
     *
     * Compares the computed HMAC-SHA256 hash of the request body
     * with the signature provided in the X-Fic-Signature header.
     *
     * @param string $body The raw request body
     * @param string $signatureHeader The signature from X-Fic-Signature header
     * @param string $secret The webhook secret
     * @return bool True if signature is valid, false otherwise
     */
    private function verifySignature(string $body, string $signatureHeader, string $secret): bool
    {
        // Compute HMAC-SHA256
        $computedSignature = hash_hmac('sha256', $body, $secret);

        // Use hash_equals for timing attack prevention
        return hash_equals($computedSignature, $signatureHeader);
    }

    /**
     * Verify JWT token from Authorization header.
     *
     * Validates the JWT token using the public key provided by Fatture in Cloud.
     * The token should contain claims matching the CloudEvents attributes.
     *
     * According to FIC documentation, the JWT contains:
     * - jti: JWT ID (should match ce-id)
     * - iss: Issuer (should be https://api-v2.fattureincloud.it)
     * - sub: Subject (should match ce-subject)
     * - aud: Audience (contains the Target's Endpoint)
     * - iat: Issued at (timestamp)
     * - aid: Application ID (FIC Application ID related to the Subscription)
     *
     * @param string $token The JWT token from Authorization header
     * @param string|null $expectedJti The expected JWT ID (from ce-id header)
     * @param string|null $expectedSubject The expected subject (from ce-subject header)
     * @return bool True if token is valid, false otherwise
     */
    private function verifyJwtToken(?string $token, ?string $expectedJti = null, ?string $expectedSubject = null): bool
    {
        if (empty($token)) {
            Log::warning('FIC Webhook: Empty JWT token provided');
            return false;
        }

        $publicKeyBase64 = config('fattureincloud.webhook_public_key');

        if (empty($publicKeyBase64)) {
            Log::warning('FIC Webhook: Webhook public key not configured', [
                'config_key' => 'fattureincloud.webhook_public_key',
            ]);

            // If public key is not configured, we can't verify, but we can allow it
            // in development environments. In production, this should be an error.
            return false;
        }

        try {
            // Decode base64 public key
            // The key is base64-encoded PEM format
            $publicKey = base64_decode($publicKeyBase64, true);

            if ($publicKey === false) {
                Log::error('FIC Webhook: Failed to decode public key from base64', [
                    'public_key_length' => strlen($publicKeyBase64),
                ]);

                return false;
            }

            // Decode and verify JWT token
            // FIC uses ES256 algorithm (ECDSA with P-256 and SHA-256)
            $decoded = JWT::decode($token, new Key($publicKey, 'ES256'));

            // Verify issuer (required by FIC)
            $expectedIssuer = 'https://api-v2.fattureincloud.it';
            if (!isset($decoded->iss) || $decoded->iss !== $expectedIssuer) {
                Log::warning('FIC Webhook: JWT iss claim does not match expected issuer', [
                    'expected_issuer' => $expectedIssuer,
                    'token_issuer' => $decoded->iss ?? null,
                ]);

                return false;
            }

            // Verify jti claim matches ce-id if provided
            if ($expectedJti !== null) {
                if (!isset($decoded->jti) || $decoded->jti !== $expectedJti) {
                    Log::warning('FIC Webhook: JWT jti claim does not match ce-id', [
                        'expected_jti' => $expectedJti,
                        'token_jti' => $decoded->jti ?? null,
                    ]);

                    return false;
                }
            }

            // Verify sub claim matches ce-subject if provided
            if ($expectedSubject !== null) {
                if (!isset($decoded->sub) || $decoded->sub !== $expectedSubject) {
                    Log::warning('FIC Webhook: JWT sub claim does not match ce-subject', [
                        'expected_subject' => $expectedSubject,
                        'token_subject' => $decoded->sub ?? null,
                    ]);

                    return false;
                }
            }

            // Log successful verification with all claims
            Log::debug('FIC Webhook: JWT token verified successfully', [
                'jti' => $decoded->jti ?? null,
                'iss' => $decoded->iss ?? null,
                'sub' => $decoded->sub ?? null,
                'aud' => $decoded->aud ?? null,
                'iat' => isset($decoded->iat) ? date('Y-m-d H:i:s', $decoded->iat) : null,
                'aid' => $decoded->aid ?? null,
            ]);

            return true;
        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::warning('FIC Webhook: JWT token expired', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            Log::warning('FIC Webhook: JWT signature invalid', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Firebase\JWT\BeforeValidException $e) {
            Log::warning('FIC Webhook: JWT token not yet valid', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::warning('FIC Webhook: JWT verification failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Fetch basic object details for display in the webhook notification.
     * 
     * This method attempts to quickly fetch minimal details (name, code, number)
     * of the affected object(s) to display in the frontend without blocking
     * the webhook response.
     *
     * @param int $accountId The FIC account ID
     * @param string $eventType The CloudEvents event type
     * @param array $ids Array of object IDs from the webhook
     * @return array|null Array of object details or null if unable to fetch
     */
    private function fetchObjectDetailsForEvent(int $accountId, string $eventType, array $ids): ?array
    {
        if (empty($ids)) {
            return null;
        }

        try {
            $account = FicAccount::find($accountId);
            if (!$account) {
                return null;
            }

            $apiService = new FicApiService($account);
            $details = [];

            // Determine object type from event type
            // Examples:
            // - it.fattureincloud.webhooks.entities.clients.create -> client
            // - it.fattureincloud.webhooks.entities.suppliers.delete -> supplier
            // - it.fattureincloud.webhooks.issued_documents.invoices.create -> invoice
            // - it.fattureincloud.webhooks.issued_documents.quotes.create -> quote

            foreach ($ids as $id) {
                try {
                    $objectData = null;

                    if (str_contains($eventType, 'entities.clients')) {
                        // Client event
                        $objectData = $apiService->fetchClientById((int) $id);
                        $details[] = [
                            'id' => $objectData['id'],
                            'name' => $objectData['name'],
                            'code' => $objectData['code'],
                            'type' => 'client',
                        ];
                    } elseif (str_contains($eventType, 'entities.suppliers')) {
                        // Supplier event
                        $objectData = $apiService->fetchSupplierById((int) $id);
                        $details[] = [
                            'id' => $objectData['id'],
                            'name' => $objectData['name'],
                            'code' => $objectData['code'],
                            'type' => 'supplier',
                        ];
                    } elseif (str_contains($eventType, 'issued_documents.invoices')) {
                        // Invoice event
                        $objectData = $apiService->fetchIssuedInvoiceById((int) $id);
                        $details[] = [
                            'id' => $objectData['id'],
                            'number' => $objectData['number'],
                            'status' => $objectData['status'],
                            'total_gross' => $objectData['total_gross'],
                            'type' => 'invoice',
                        ];
                    } elseif (str_contains($eventType, 'issued_documents.quotes')) {
                        // Quote event
                        $objectData = $apiService->fetchIssuedQuoteById((int) $id);
                        $details[] = [
                            'id' => $objectData['id'],
                            'number' => $objectData['number'],
                            'status' => $objectData['status'],
                            'total_gross' => $objectData['total_gross'],
                            'type' => 'quote',
                        ];
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - we'll just show IDs if details can't be fetched
                    Log::debug('FIC Webhook: Could not fetch object details for display', [
                        'account_id' => $accountId,
                        'event_type' => $eventType,
                        'object_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return !empty($details) ? $details : null;
        } catch (\Exception $e) {
            // Don't fail the webhook if we can't fetch details
            Log::debug('FIC Webhook: Error fetching object details for display', [
                'account_id' => $accountId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}