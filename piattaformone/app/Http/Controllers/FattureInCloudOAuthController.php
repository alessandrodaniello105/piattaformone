<?php

namespace App\Http\Controllers;

use App\Models\FicAccount;
use FattureInCloud\Configuration;
use FattureInCloud\OAuth2\OAuth2AuthorizationCode\OAuth2AuthorizationCodeManager;
use FattureInCloud\OAuth2\Scope;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class FattureInCloudOAuthController extends Controller
{
    /**
     * Redis key prefix for OAuth tokens
     */
    private const REDIS_KEY_PREFIX = 'fic:oauth:';

    /**
     * TTL for OAuth state (10 minutes)
     */
    private const STATE_TTL = 600;

    /**
     * Redirect the user to Fatture in Cloud authorization page.
     *
     * @param OAuth2AuthorizationCodeManager $oauthManager
     * @return RedirectResponse
     */
    public function redirect(OAuth2AuthorizationCodeManager $oauthManager): RedirectResponse
    {
        // Generate a random state for CSRF protection
        $state = Str::random(40);
        
        // Store state in Redis for verification in callback (10 minutes TTL)
        try {
            Redis::setex(self::REDIS_KEY_PREFIX . 'state:' . $state, self::STATE_TTL, $state);
        } catch (\Exception $e) {
            Log::error('FIC OAuth: Failed to store state in Redis', [
                'error' => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', 'Errore di connessione a Redis. Verifica la configurazione.');
        }
        
        // Define required scopes
        // For webhooks, we only need read scopes to get company info
        // The webhook notifications come from FIC, not from API calls
        // See: https://developers.fattureincloud.it/docs/general-knowledge/oauth2/
        $scopes = [
            Scope::ENTITY_CLIENTS_READ,
            Scope::ENTITY_SUPPLIERS_READ,
            Scope::ISSUED_DOCUMENTS_QUOTES_READ, 
            Scope::ISSUED_DOCUMENTS_INVOICES_READ,
        ];
        
        try {
            // Get and validate redirect URI
            $redirectUri = config('fattureincloud.redirect_uri');
            
            if (empty($redirectUri)) {
                Log::error('FIC OAuth: Redirect URI is not configured');
                return redirect()->back()->with('error', 'Redirect URI non configurato. Verifica FIC_REDIRECT_URI nel .env');
            }
            
            // Log detailed information for debugging
            Log::info('FIC OAuth redirect - Debug Info', [
                'redirect_uri' => $redirectUri,
                'redirect_uri_length' => strlen($redirectUri),
                'redirect_uri_bytes' => bin2hex($redirectUri), // Show exact bytes to catch hidden characters
                'client_id' => config('fattureincloud.client_id'),
            ]);
            
            // Get authorization URL from OAuth manager
            // The SDK will use the redirect_uri passed to the constructor
            $authorizationUrl = $oauthManager->getAuthorizationUrl($scopes, $state);
            
            // Log the generated authorization URL to see what redirect_uri is being sent
            Log::info('FIC OAuth authorization URL generated', [
                'authorization_url' => $authorizationUrl,
            ]);
            
            // Extract redirect_uri from the authorization URL to verify
            $parsedUrl = parse_url($authorizationUrl);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['redirect_uri'])) {
                    Log::info('FIC OAuth redirect_uri in authorization URL', [
                        'redirect_uri_in_url' => urldecode($queryParams['redirect_uri']),
                        'matches_config' => urldecode($queryParams['redirect_uri']) === $redirectUri,
                    ]);
                }
            }
            
            // Redirect user to Fatture in Cloud authorization page
            return redirect()->away($authorizationUrl);
        } catch (\Exception $e) {
            Log::error('FIC OAuth redirect error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->back()->with('error', 'Errore durante la generazione dell\'URL di autorizzazione: ' . $e->getMessage());
        }
    }

    /**
     * Handle the OAuth callback from Fatture in Cloud.
     *
     * @param Request $request
     * @param OAuth2AuthorizationCodeManager $oauthManager
     * @return RedirectResponse
     */
    public function callback(Request $request, OAuth2AuthorizationCodeManager $oauthManager): RedirectResponse
    {
        // Verify state parameter to prevent CSRF attacks
        $receivedState = $request->query('state');
        $storedState = null;
        
        try {
            $storedState = $receivedState ? Redis::get(self::REDIS_KEY_PREFIX . 'state:' . $receivedState) : null;
        } catch (\Exception $e) {
            Log::error('FIC OAuth: Failed to retrieve state from Redis', [
                'error' => $e->getMessage(),
            ]);
            return redirect('/')->with('error', 'Errore di connessione a Redis. Verifica la configurazione.');
        }
        
        if (!$receivedState || !$storedState || $receivedState !== $storedState) {
            Log::warning('FIC OAuth callback: Invalid state parameter', [
                'received' => $receivedState,
                'stored' => $storedState ? 'exists' : 'not found',
            ]);
            
            return redirect('/')->with('error', 'Richiesta non valida. Riprova.');
        }
        
        // Check for authorization error
        if ($request->has('error')) {
            $error = $request->query('error');
            $errorDescription = $request->query('error_description', 'Errore sconosciuto');
            
            Log::warning('FIC OAuth callback error', [
                'error' => $error,
                'description' => $errorDescription,
                'redirect_uri_configured' => config('fattureincloud.redirect_uri'),
                'current_url' => $request->fullUrl(),
            ]);
            
            // Provide helpful error message for redirect URI mismatch
            if ($error === 'access_denied' && str_contains($errorDescription, 'Redirect URI')) {
                $message = "Redirect URI non valido. Verifica che FIC_REDIRECT_URI nel .env corrisponda ESATTAMENTE a quello registrato nell'app Fatture in Cloud.\n";
                $message .= "URI configurato: " . config('fattureincloud.redirect_uri') . "\n";
                $message .= "URI corrente: " . $request->url();
                
                return redirect('/')->with('error', $message);
            }
            
            return redirect('/')->with('error', "Autorizzazione negata: {$errorDescription}");
        }
        
        // Get authorization code
        $code = $request->query('code');
        
        if (!$code) {
            Log::error('FIC OAuth callback: No authorization code received');
            
            return redirect('/')->with('error', 'Codice di autorizzazione non ricevuto.');
        }
        
        try {
            // Exchange authorization code for access token
            $tokenResponse = $oauthManager->fetchToken($code);
            
            // Get tokens from response
            $accessToken = $tokenResponse->getAccessToken();
            $refreshToken = $tokenResponse->getRefreshToken();
            $expiresIn = $tokenResponse->getExpiresIn();
            
            // Store tokens in Redis with appropriate TTL
            // Access token expires in the time provided by FIC (usually 3600 seconds)
            // Refresh token is stored with a longer TTL (30 days) as it doesn't expire
            $expiresAt = now()->addSeconds($expiresIn);
            
            try {
                Redis::setex(self::REDIS_KEY_PREFIX . 'access_token', $expiresIn, $accessToken);
                Redis::setex(self::REDIS_KEY_PREFIX . 'refresh_token', 30 * 24 * 60 * 60, $refreshToken); // 30 days
                Redis::setex(self::REDIS_KEY_PREFIX . 'token_expires_at', $expiresIn, $expiresAt->toIso8601String());
                
                // Clear the state from Redis
                Redis::del(self::REDIS_KEY_PREFIX . 'state:' . $receivedState);
            } catch (\Exception $e) {
                Log::error('FIC OAuth: Failed to store tokens in Redis', [
                    'error' => $e->getMessage(),
                ]);
                return redirect('/')->with('error', 'Errore durante il salvataggio dei token: ' . $e->getMessage());
            }
            
            Log::info('FIC OAuth: Tokens obtained successfully', [
                'expires_in' => $expiresIn,
            ]);

            // Create or update FicAccount in database
            try {
                Log::info('FIC OAuth: Attempting to create/update account in database', [
                    'has_access_token' => !empty($accessToken),
                    'has_refresh_token' => !empty($refreshToken),
                ]);

                $account = $this->createOrUpdateFicAccount(
                    $accessToken,
                    $refreshToken,
                    $expiresAt
                );

                Log::info('FIC OAuth: Account created/updated in database', [
                    'account_id' => $account->id,
                    'company_id' => $account->company_id,
                    'company_name' => $account->company_name,
                ]);

                return redirect('/')->with('success', "Autorizzazione completata con successo! Account ID: {$account->id}");
            } catch (\Exception $e) {
                Log::error('FIC OAuth: Failed to create/update account in database', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Still redirect with success since tokens are in Redis
                // But show a warning message
                return redirect('/')->with('warning', 'Autorizzazione completata, ma errore nel salvataggio account. Controlla i log. Errore: ' . $e->getMessage());
            }
            
        } catch (\Exception $e) {
            Log::error('FIC OAuth callback error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            
            return redirect('/')->with('error', 'Errore durante l\'ottenimento dei token: ' . $e->getMessage());
        }
    }

    /**
     * Check OAuth token status.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $accessToken = Redis::get(self::REDIS_KEY_PREFIX . 'access_token');
        $refreshToken = Redis::get(self::REDIS_KEY_PREFIX . 'refresh_token');
        $tokenExpiresAtString = Redis::get(self::REDIS_KEY_PREFIX . 'token_expires_at');
        $tokenExpiresAt = $tokenExpiresAtString ? \Carbon\Carbon::parse($tokenExpiresAtString) : null;
        
        return response()->json([
            'redis' => [
                'has_access_token' => !empty($accessToken),
                'has_refresh_token' => !empty($refreshToken),
                'token_expires_at' => $tokenExpiresAt ? $tokenExpiresAt->toIso8601String() : null,
                'token_expired' => $tokenExpiresAt ? $tokenExpiresAt->isPast() : null,
                'ttl_access_token' => Redis::ttl(self::REDIS_KEY_PREFIX . 'access_token'),
                'ttl_refresh_token' => Redis::ttl(self::REDIS_KEY_PREFIX . 'refresh_token'),
            ],
            'config' => [
                'has_access_token' => !empty(config('fattureincloud.access_token')),
                'has_refresh_token' => !empty(config('fattureincloud.refresh_token')),
            ],
            'access_token_preview' => $accessToken ? substr($accessToken, 0, 20) . '...' : null,
        ]);
    }

    /**
     * Debug endpoint to show redirect URI configuration.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function debug(): \Illuminate\Http\JsonResponse
    {
        $redirectUri = config('fattureincloud.redirect_uri');
        $clientId = config('fattureincloud.client_id');
        
        // Create a temporary OAuth manager to see what redirect URI it uses
        try {
            $oauthManager = new OAuth2AuthorizationCodeManager(
                $clientId,
                config('fattureincloud.client_secret'),
                $redirectUri
            );
            
            // Generate a test state to see the authorization URL
            $testState = 'test_state_for_debug';
            $testScopes = [Scope::ENTITY_CLIENTS_READ];
            $authorizationUrl = $oauthManager->getAuthorizationUrl($testScopes, $testState);
            
            // Parse the URL to extract redirect_uri parameter
            $parsedUrl = parse_url($authorizationUrl);
            $redirectUriInUrl = null;
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                $redirectUriInUrl = $queryParams['redirect_uri'] ?? null;
            }
            
            return response()->json([
                'config' => [
                    'redirect_uri_from_config' => $redirectUri,
                    'redirect_uri_length' => strlen($redirectUri),
                    'redirect_uri_hex' => bin2hex($redirectUri),
                ],
                'authorization_url' => [
                    'full_url' => $authorizationUrl,
                    'redirect_uri_parameter' => $redirectUriInUrl ? urldecode($redirectUriInUrl) : null,
                    'redirect_uri_encoded' => $redirectUriInUrl,
                ],
                'comparison' => [
                    'matches' => $redirectUriInUrl ? urldecode($redirectUriInUrl) === $redirectUri : false,
                    'config_uri' => $redirectUri,
                    'url_parameter_uri' => $redirectUriInUrl ? urldecode($redirectUriInUrl) : null,
                ],
                'instructions' => [
                    '1' => 'Copy the "redirect_uri_from_config" value above',
                    '2' => 'Go to https://developers.fattureincloud.it and open your app',
                    '3' => 'Check the Redirect URI field in your app settings',
                    '4' => 'It must match EXACTLY (character by character)',
                    '5' => 'Check for: spaces, trailing slashes, protocol (http vs https), port numbers',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'config' => [
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                ],
            ], 500);
        }
    }

    /**
     * Test endpoint to verify connection (requires valid token).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function test(Request $request)
    {
        // Try to get token from Redis first, then from config
        $accessToken = Redis::get(self::REDIS_KEY_PREFIX . 'access_token');
        $refreshToken = Redis::get(self::REDIS_KEY_PREFIX . 'refresh_token');
        $tokenExpiresAtString = Redis::get(self::REDIS_KEY_PREFIX . 'token_expires_at');
        $tokenExpiresAt = $tokenExpiresAtString ? \Carbon\Carbon::parse($tokenExpiresAtString) : null;
        
        // Fallback to config if not in Redis
        if (!$accessToken) {
            $accessToken = config('fattureincloud.access_token');
        }
        
        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Nessun access token disponibile. Esegui prima il flusso OAuth.',
                'debug' => [
                    'redis_has_token' => !empty(Redis::get(self::REDIS_KEY_PREFIX . 'access_token')),
                    'config_has_token' => !empty(config('fattureincloud.access_token')),
                ],
            ], 401);
        }
        
        try {
            // Create configuration with access token
            $config = \FattureInCloud\Configuration::getDefaultConfiguration();
            $config->setAccessToken($accessToken);
            
            // Create API client (example: UserApi to get user companies)
            $userApi = new \FattureInCloud\Api\UserApi(
                new \GuzzleHttp\Client(),
                $config
            );
            
            // Test API call: list user companies
            $response = $userApi->listUserCompanies();
            $companiesData = $response->getData();
            $companies = $companiesData ? $companiesData->getCompanies() : [];
            
            return response()->json([
                'success' => true,
                'message' => 'Connessione a Fatture in Cloud riuscita!',
                'data' => [
                    'companies_count' => is_array($companies) ? count($companies) : 0,
                    'companies' => $companies,
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il test della connessione: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update FicAccount in database with OAuth tokens.
     *
     * Fetches company information from FIC API and creates/updates the account.
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param \Carbon\Carbon $expiresAt
     * @return FicAccount
     * @throws \Exception
     */
    private function createOrUpdateFicAccount(
        string $accessToken,
        string $refreshToken,
        \Carbon\Carbon $expiresAt
    ): FicAccount {
        Log::debug('FIC OAuth: Starting createOrUpdateFicAccount');

        // Initialize FIC SDK configuration
        $config = Configuration::getDefaultConfiguration();
        $config->setAccessToken($accessToken);

        $httpClient = new Client([
            'timeout' => 30.0,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Get user companies to determine which company to use
        Log::debug('FIC OAuth: Calling listUserCompanies API');
        try {
            $userApi = new \FattureInCloud\Api\UserApi($httpClient, $config);
            $response = $userApi->listUserCompanies();
            $companiesData = $response->getData();
            $companies = $companiesData ? $companiesData->getCompanies() : [];

            Log::debug('FIC OAuth: Received companies from API', [
                'companies_count' => is_array($companies) ? count($companies) : (is_object($companies) ? 1 : 0),
                'companies_type' => gettype($companies),
            ]);
        } catch (\Exception $e) {
            Log::error('FIC OAuth: Error calling listUserCompanies', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new \RuntimeException('Failed to fetch companies from FIC API: ' . $e->getMessage(), 0, $e);
        }

        if (empty($companies)) {
            throw new \RuntimeException('No companies found for this user');
        }

        // Use the first company (or you could add logic to select a specific one)
        $company = is_array($companies) ? $companies[0] : $companies;
        $companyId = is_object($company) ? $company->getId() : $company['id'] ?? null;
        $companyName = is_object($company) ? $company->getName() : $company['name'] ?? null;
        
        // Company email is not available in the Company object from listUserCompanies
        // It can be retrieved later from company info if needed
        $companyEmail = null;

        Log::debug('FIC OAuth: Extracted company info', [
            'company_id' => $companyId,
            'company_name' => $companyName,
            'company_type' => gettype($company),
        ]);

        if (!$companyId) {
            throw new \RuntimeException('Company ID not found in API response');
        }

        // Find existing account by company_id or create new one
        Log::debug('FIC OAuth: Looking for existing account or creating new one', [
            'company_id' => $companyId,
        ]);

        $account = FicAccount::firstOrNew([
            'company_id' => $companyId,
        ]);

        $isNew = !$account->exists;

        // Update account with tokens and company info
        $account->fill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $expiresAt,
            'token_refreshed_at' => now(),
            'company_name' => $companyName,
            'company_email' => $companyEmail,
            'status' => 'active',
            'connected_at' => $account->connected_at ?? now(),
        ]);

        // Set name if not already set
        if (empty($account->name)) {
            $account->name = $companyName ?? "Account {$companyId}";
        }

        Log::debug('FIC OAuth: Saving account to database', [
            'is_new' => $isNew,
            'account_data' => [
                'company_id' => $account->company_id,
                'company_name' => $account->company_name,
                'name' => $account->name,
                'has_access_token' => !empty($account->access_token),
            ],
        ]);

        $account->save();

        Log::info('FIC OAuth: Account saved successfully', [
            'account_id' => $account->id,
            'was_new' => $isNew,
        ]);

        return $account;
    }
}
