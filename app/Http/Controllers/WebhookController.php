<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * Expected issuer for JWT tokens from Fatture in Cloud
     */
    private const EXPECTED_ISSUER = 'https://api-v2.fattureincloud.it';

    /**
     * Handle webhook requests from Fatture in Cloud.
     * 
     * Supports two types of requests:
     * 1. GET: Subscription verification (challenge/response)
     * 2. POST: Webhook notifications (with JWT verification)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        // Handle subscription verification (GET request with challenge)
        if ($request->isMethod('GET')) {
            return $this->handleSubscriptionVerification($request);
        }

        // Handle webhook notification (POST request with JWT)
        if ($request->isMethod('POST')) {
            return $this->handleWebhookNotification($request);
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
     * @return JsonResponse
     */
    private function handleSubscriptionVerification(Request $request): JsonResponse
    {
        // Get challenge from header or query parameter
        $challenge = $request->header('x-fic-verification-challenge') 
                  ?? $request->query('x-fic-verification-challenge');

        if (!$challenge) {
            Log::warning('FIC Webhook: Subscription verification request without challenge', [
                'headers' => $request->headers->all(),
                'query' => $request->query->all(),
            ]);

            return response()->json([
                'error' => 'Missing verification challenge'
            ], 400);
        }

        Log::info('FIC Webhook: Subscription verification request received', [
            'challenge' => $challenge,
            'ip' => $request->ip(),
        ]);

        // Log the verification request
        try {
            WebhookLog::create([
                'webhook_event' => 'subscription.verification',
                'event_type' => 'verification',
                'payload' => [
                    'challenge' => $challenge,
                    'method' => 'GET',
                ],
                'headers' => $request->headers->all(),
                'ip_address' => $request->ip(),
                'status' => 'processed',
                'response_code' => 200,
                'response_body' => json_encode(['verification' => $challenge]),
                'received_at' => now(),
                'processed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Webhook: Failed to log verification request', [
                'error' => $e->getMessage(),
            ]);
        }

        // Return the challenge token as required by Fatture in Cloud
        return response()->json([
            'verification' => $challenge
        ], 200);
    }

    /**
     * Handle webhook notification (POST request with JWT).
     * 
     * Verifies the JWT signature, validates claims, and processes
     * the webhook event.
     *
     * @param Request $request
     * @return JsonResponse
     */
    private function handleWebhookNotification(Request $request): JsonResponse
    {
        $webhookLog = null;
        $startTime = microtime(true);

        try {
            // Extract JWT token from Authorization header
            $authHeader = $request->header('Authorization');
            
            if (!$authHeader || !Str::startsWith($authHeader, 'Bearer ')) {
                return $this->handleWebhookError(
                    $request,
                    'Missing or invalid Authorization header',
                    401,
                    null
                );
            }

            $token = Str::after($authHeader, 'Bearer ');
            
            // Get webhook payload
            $payload = $request->all();
            $headers = $request->headers->all();
            $ipAddress = $request->ip();

            // Create webhook log entry (before verification)
            $webhookLog = WebhookLog::create([
                'webhook_event' => $payload['event'] ?? 'unknown',
                'event_type' => $this->extractEventType($payload['event'] ?? 'unknown'),
                'payload' => $payload,
                'headers' => $headers,
                'signature' => $token,
                'ip_address' => $ipAddress,
                'status' => 'received',
                'received_at' => now(),
                'company_id' => $payload['company_id'] ?? config('fattureincloud.company_id'),
            ]);

            // Verify JWT token
            $decodedToken = $this->verifyJwtToken($token);

            if (!$decodedToken) {
                $webhookLog->update([
                    'status' => 'error',
                    'error_message' => 'JWT verification failed',
                    'response_code' => 401,
                ]);

                return response()->json([
                    'error' => 'Invalid or expired token'
                ], 401);
            }

            // Validate JWT claims
            $validationError = $this->validateJwtClaims($decodedToken);
            
            if ($validationError) {
                $webhookLog->update([
                    'status' => 'error',
                    'error_message' => $validationError,
                    'response_code' => 403,
                ]);

                return response()->json([
                    'error' => $validationError
                ], 403);
            }

            // Update log with verified status
            $webhookLog->update([
                'status' => 'processing',
            ]);

            // Process the webhook event
            // TODO: Implement actual event processing logic here
            // For now, we just log it as processed
            $this->processWebhookEvent($payload, $decodedToken);

            // Mark as processed
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $webhookLog->update([
                'status' => 'processed',
                'processed_at' => now(),
                'response_code' => 200,
                'response_body' => json_encode(['success' => true, 'processing_time_ms' => $processingTime]),
            ]);

            Log::info('FIC Webhook: Notification processed successfully', [
                'event' => $payload['event'] ?? 'unknown',
                'jti' => $decodedToken->jti ?? null,
                'company_id' => $decodedToken->aid ?? null,
                'processing_time_ms' => $processingTime,
            ]);

            return response()->json([
                'success' => true,
                'processing_time_ms' => $processingTime,
            ], 200);

        } catch (\Exception $e) {
            Log::error('FIC Webhook: Error processing notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($webhookLog) {
                $webhookLog->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'response_code' => 500,
                ]);
            } else {
                // Try to create a log entry even if we failed early
                try {
                    WebhookLog::create([
                        'webhook_event' => 'error',
                        'event_type' => 'error',
                        'payload' => $request->all(),
                        'headers' => $request->headers->all(),
                        'ip_address' => $request->ip(),
                        'status' => 'error',
                        'error_message' => $e->getMessage(),
                        'response_code' => 500,
                        'received_at' => now(),
                    ]);
                } catch (\Exception $logError) {
                    // If we can't even log, just continue
                    Log::error('FIC Webhook: Failed to create error log entry', [
                        'error' => $logError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Verify JWT token signature.
     *
     * @param string $token
     * @return object|null Decoded token object or null if verification fails
     */
    private function verifyJwtToken(string $token): ?object
    {
        $publicKey = config('fattureincloud.webhook_public_key');

        if (!$publicKey) {
            Log::error('FIC Webhook: Webhook public key not configured');
            return null;
        }

        try {
            // Decode base64 public key
            $decodedKey = base64_decode($publicKey, true);
            
            if ($decodedKey === false) {
                Log::error('FIC Webhook: Failed to decode base64 public key');
                return null;
            }

            // Verify and decode JWT
            // Fatture in Cloud uses ES256 algorithm (ECDSA with P-256)
            $decoded = JWT::decode($token, new Key($decodedKey, 'ES256'));

            return $decoded;

        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::warning('FIC Webhook: JWT token expired', [
                'error' => $e->getMessage(),
            ]);
            return null;

        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            Log::warning('FIC Webhook: JWT signature invalid', [
                'error' => $e->getMessage(),
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('FIC Webhook: JWT verification error', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Validate JWT claims.
     *
     * @param object $decodedToken
     * @return string|null Error message if validation fails, null if valid
     */
    private function validateJwtClaims(object $decodedToken): ?string
    {
        // Verify issuer
        if (!isset($decodedToken->iss) || $decodedToken->iss !== self::EXPECTED_ISSUER) {
            return 'Invalid issuer';
        }

        // Verify expiration
        if (!isset($decodedToken->exp) || $decodedToken->exp < time()) {
            return 'Token expired';
        }

        // Verify audience (optional, but recommended)
        $webhookUrl = config('fattureincloud.webhook_url');
        if ($webhookUrl && isset($decodedToken->aud)) {
            $audience = is_array($decodedToken->aud) ? $decodedToken->aud : [$decodedToken->aud];
            if (!in_array($webhookUrl, $audience)) {
                Log::warning('FIC Webhook: Audience mismatch', [
                    'expected' => $webhookUrl,
                    'received' => $decodedToken->aud,
                ]);
                // Don't fail on audience mismatch, just log it
            }
        }

        return null;
    }

    /**
     * Process webhook event.
     * 
     * This method should be extended to handle different event types.
     *
     * @param array $payload
     * @param object $decodedToken
     * @return void
     */
    private function processWebhookEvent(array $payload, object $decodedToken): void
    {
        $event = $payload['event'] ?? 'unknown';
        
        Log::info('FIC Webhook: Processing event', [
            'event' => $event,
            'jti' => $decodedToken->jti ?? null,
            'company_id' => $decodedToken->aid ?? null,
        ]);

        // TODO: Implement event-specific processing logic
        // Examples:
        // - invoice.created -> create invoice in local system
        // - payment.received -> update payment status
        // - client.updated -> sync client data
        // etc.
    }

    /**
     * Extract event type from event name.
     *
     * @param string $eventName
     * @return string
     */
    private function extractEventType(string $eventName): string
    {
        // Extract the entity type from event name (e.g., "invoice.created" -> "invoice")
        $parts = explode('.', $eventName);
        return $parts[0] ?? 'unknown';
    }

    /**
     * Handle webhook error and log it.
     *
     * @param Request $request
     * @param string $errorMessage
     * @param int $statusCode
     * @param WebhookLog|null $webhookLog
     * @return JsonResponse
     */
    private function handleWebhookError(
        Request $request,
        string $errorMessage,
        int $statusCode,
        ?WebhookLog $webhookLog
    ): JsonResponse {
        Log::warning('FIC Webhook: Error processing request', [
            'error' => $errorMessage,
            'status_code' => $statusCode,
            'ip' => $request->ip(),
        ]);

        if ($webhookLog) {
            $webhookLog->update([
                'status' => 'error',
                'error_message' => $errorMessage,
                'response_code' => $statusCode,
            ]);
        } else {
            try {
                WebhookLog::create([
                    'webhook_event' => 'error',
                    'event_type' => 'error',
                    'payload' => $request->all(),
                    'headers' => $request->headers->all(),
                    'ip_address' => $request->ip(),
                    'status' => 'error',
                    'error_message' => $errorMessage,
                    'response_code' => $statusCode,
                    'received_at' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('FIC Webhook: Failed to create error log', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'error' => $errorMessage
        ], $statusCode);
    }
}
