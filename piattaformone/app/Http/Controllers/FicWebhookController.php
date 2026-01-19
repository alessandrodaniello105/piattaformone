<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessFicWebhook;
use App\Models\FicSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for handling Fatture in Cloud webhook requests.
 *
 * Supports dynamic routes with account_id and event_group parameters.
 * Validates webhook signatures using HMAC-SHA256 with the webhook_secret.
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
        // Get challenge from header or query parameter
        $challenge = $request->header('x-fic-verification-challenge')
                  ?? $request->query('x-fic-verification-challenge');

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
     * Handle webhook notification (POST request with signature).
     *
     * Validates the X-Fic-Signature header using HMAC-SHA256
     * with the webhook_secret from the FicSubscription.
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
        // Retrieve the subscription to get the webhook_secret
        $subscription = FicSubscription::where('fic_account_id', $accountId)
            ->where('event_group', $group)
            ->where('is_active', true)
            ->first();

        if (!$subscription) {
            Log::warning('FIC Webhook: Subscription not found', [
                'account_id' => $accountId,
                'event_group' => $group,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Subscription not found or inactive'
            ], 404);
        }

        // Get the raw request body for signature verification
        $rawBody = $request->getContent();

        // Get signature from header
        $signatureHeader = $request->header('X-Fic-Signature');

        if (!$signatureHeader) {
            Log::warning('FIC Webhook: Missing X-Fic-Signature header', [
                'account_id' => $accountId,
                'event_group' => $group,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Missing signature header'
            ], 401);
        }

        // Get webhook secret (it's encrypted in the model, so it will be decrypted automatically)
        $webhookSecret = $subscription->webhook_secret;

        if (empty($webhookSecret)) {
            Log::error('FIC Webhook: Webhook secret is missing for subscription', [
                'account_id' => $accountId,
                'event_group' => $group,
                'subscription_id' => $subscription->id,
            ]);

            return response()->json([
                'error' => 'Webhook secret not configured'
            ], 500);
        }

        // Verify signature
        if (!$this->verifySignature($rawBody, $signatureHeader, $webhookSecret)) {
            Log::warning('FIC Webhook: Invalid signature', [
                'account_id' => $accountId,
                'event_group' => $group,
                'ip' => $request->ip(),
                'signature_received' => $signatureHeader,
            ]);

            return response()->json([
                'error' => 'Invalid signature'
            ], 401);
        }

        // Parse payload
        $payload = $request->all();

        if (empty($payload)) {
            Log::warning('FIC Webhook: Empty payload', [
                'account_id' => $accountId,
                'event_group' => $group,
            ]);

            return response()->json([
                'error' => 'Empty payload'
            ], 400);
        }

        // Dispatch job for async processing
        try {
            ProcessFicWebhook::dispatch($payload, $accountId, $group)
                ->onConnection('redis');

            Log::info('FIC Webhook: Notification queued for processing', [
                'account_id' => $accountId,
                'event_group' => $group,
                'event' => $payload['event'] ?? 'unknown',
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
}