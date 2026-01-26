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
     * Uses team-specific FIC OAuth credentials for multi-tenant support.
     *
     * @return RedirectResponse
     */
    public function redirect(Request $request): RedirectResponse
    {
        // Get authenticated user and their current team
        $user = $request->user();
        
        if (!$user) {
            Log::error('FIC OAuth redirect: User not authenticated');
            return redirect()->route('dashboard')->with('error', 'Devi essere autenticato per connettere Fatture in Cloud.');
        }
        
        $team = $user->currentTeam;
        
        if (!$team) {
            Log::error('FIC OAuth redirect: No current team', ['user_id' => $user->id]);
            return redirect()->route('dashboard')->with('error', 'Nessun team selezionato.');
        }
        
        // Check if team has FIC credentials configured
        if (!$team->hasFicCredentials()) {
            Log::warning('FIC OAuth redirect: Team has no FIC credentials', [
                'team_id' => $team->id,
                'team_name' => $team->name,
            ]);
            
            // Fallback to .env credentials if available
            if (!config('fattureincloud.client_id') || !config('fattureincloud.client_secret')) {
                return redirect()->route('dashboard')->with('error', 
                    'Configura le credenziali Fatture in Cloud per questo team nelle impostazioni.');
            }
            
            Log::info('FIC OAuth redirect: Using fallback credentials from .env', [
                'team_id' => $team->id,
            ]);
        }
        
        // Use team credentials if available, otherwise fallback to .env
        $clientId = $team->fic_client_id ?? config('fattureincloud.client_id');
        $clientSecret = $team->fic_client_secret ?? config('fattureincloud.client_secret');
        $redirectUri = $team->fic_redirect_uri ?? config('fattureincloud.redirect_uri');
        $scopes = $team->getFicScopes();
        
        // FIC requires at least one scope - use defaults if empty
        if (empty($scopes)) {
            $scopes = [
                'entity:clients:r',
                'entity:suppliers:r',
                'issued_documents:quotes:r',
                'issued_documents:invoices:r',
            ];
            
            Log::info('FIC OAuth redirect: Using default scopes (team has none configured)', [
                'team_id' => $team->id,
                'default_scopes' => $scopes,
            ]);
        }
        
        Log::info('FIC OAuth redirect: Using credentials', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'source' => $team->hasFicCredentials() ? 'team' : 'env',
        ]);
        
        // Create OAuth manager with team-specific credentials
        $oauthManager = new OAuth2AuthorizationCodeManager(
            $clientId,
            $clientSecret,
            $redirectUri
        );
        
        // Generate a random state for CSRF protection
        $state = Str::random(40);
        
        // Store state in Redis with user and tenant info for verification in callback (10 minutes TTL)
        $stateData = [
            'state' => $state,
            'user_id' => $user->id,
            'tenant_id' => $user->current_team_id,
        ];
        
        try {
            Redis::setex(
                self::REDIS_KEY_PREFIX . 'state:' . $state,
                self::STATE_TTL,
                json_encode($stateData)
            );
        } catch (\Exception $e) {
            Log::error('FIC OAuth: Failed to store state in Redis', [
                'error' => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', 'Errore di connessione a Redis. Verifica la configurazione.');
        }
        
        try {
            if (empty($redirectUri)) {
                Log::error('FIC OAuth: Redirect URI is not configured', [
                    'team_id' => $team->id,
                ]);
                return redirect()->back()->with('error', 'Redirect URI non configurato per questo team.');
            }
            
            // Convert string scopes to Scope constants
            $scopeObjects = array_map(function ($scope) {
                // Map string scope names to Scope constants
                return match ($scope) {
                    'entity:clients:r' => Scope::ENTITY_CLIENTS_READ,
                    'entity:clients:a' => Scope::ENTITY_CLIENTS_ALL,
                    'entity:suppliers:r' => Scope::ENTITY_SUPPLIERS_READ,
                    'entity:suppliers:a' => Scope::ENTITY_SUPPLIERS_ALL,
                    'issued_documents:invoices:r' => Scope::ISSUED_DOCUMENTS_INVOICES_READ,
                    'issued_documents:invoices:a' => Scope::ISSUED_DOCUMENTS_INVOICES_ALL,
                    'issued_documents:quotes:r' => Scope::ISSUED_DOCUMENTS_QUOTES_READ,
                    'issued_documents:quotes:a' => Scope::ISSUED_DOCUMENTS_QUOTES_ALL,
                    'settings:all' => Scope::SETTINGS_ALL,
                    default => $scope, // If it's already a constant, use as-is
                };
            }, $scopes);
            
            // Get authorization URL from OAuth manager
            $authorizationUrl = $oauthManager->getAuthorizationUrl($scopeObjects, $state);
            
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
     * Uses team-specific FIC OAuth credentials for multi-tenant support.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function callback(Request $request): RedirectResponse
    {
        // Verify state parameter to prevent CSRF attacks
        $receivedState = $request->query('state');
        $storedStateData = null;
        
        try {
            $storedStateJson = $receivedState ? Redis::get(self::REDIS_KEY_PREFIX . 'state:' . $receivedState) : null;
            if ($storedStateJson) {
                $storedStateData = json_decode($storedStateJson, true);
            }
        } catch (\Exception $e) {
            Log::error('FIC OAuth: Failed to retrieve state from Redis', [
                'error' => $e->getMessage(),
            ]);
            return redirect('/')->with('error', 'Errore di connessione a Redis. Verifica la configurazione.');
        }
        
        if (!$receivedState || !$storedStateData || $receivedState !== ($storedStateData['state'] ?? null)) {
            Log::warning('FIC OAuth callback: Invalid state parameter', [
                'received' => $receivedState,
                'stored' => $storedStateData ? 'exists' : 'not found',
            ]);
            
            return redirect('/')->with('error', 'Richiesta non valida. Riprova.');
        }
        
        // Extract user_id and tenant_id from stored state
        $userId = $storedStateData['user_id'] ?? null;
        $tenantId = $storedStateData['tenant_id'] ?? null;
        
        if (!$userId || !$tenantId) {
            Log::error('FIC OAuth callback: User ID or Tenant ID not found in state', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ]);
            return redirect('/')->with('error', 'Errore: informazioni mancanti. Riprova la connessione.');
        }
        
        // Load team to get OAuth credentials
        $team = \App\Models\Team::find($tenantId);
        
        if (!$team) {
            Log::error('FIC OAuth callback: Team not found', ['tenant_id' => $tenantId]);
            return redirect('/')->with('error', 'Team non trovato. Riprova la connessione.');
        }
        
        // Get team's FIC credentials (with fallback to .env)
        $clientId = $team->fic_client_id ?? config('fattureincloud.client_id');
        $clientSecret = $team->fic_client_secret ?? config('fattureincloud.client_secret');
        $redirectUri = $team->fic_redirect_uri ?? config('fattureincloud.redirect_uri');
        
        Log::info('FIC OAuth callback: Using credentials', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'client_id' => $clientId,
            'has_client_secret' => !empty($clientSecret),
            'redirect_uri' => $redirectUri,
            'team_has_credentials' => $team->hasFicCredentials(),
            'source' => $team->hasFicCredentials() ? 'team' : 'env',
        ]);
        
        // Validate credentials are present
        if (empty($clientId) || empty($clientSecret)) {
            Log::error('FIC OAuth callback: Missing credentials', [
                'team_id' => $team->id,
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret),
                'team_fic_client_id' => $team->fic_client_id,
                'team_has_credentials' => $team->hasFicCredentials(),
            ]);
            return redirect('/')->with('error', 'Credenziali FIC mancanti per questo team. Configurale nelle impostazioni del team.');
        }
        
        // Create OAuth manager with team-specific credentials
        $oauthManager = new OAuth2AuthorizationCodeManager(
            $clientId,
            $clientSecret,
            $redirectUri
        );
        
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
            Log::info('FIC OAuth callback: Exchanging authorization code for tokens', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'code_length' => strlen($code),
            ]);
            
            // Exchange authorization code for access token
            Log::debug('FIC OAuth callback: About to call fetchToken', [
                'code_length' => strlen($code),
                'client_id' => substr($clientId, 0, 10) . '...',
                'redirect_uri' => $redirectUri,
            ]);
            
            $tokenResponse = $oauthManager->fetchToken($code);
            
            // Get tokens from response
            $accessToken = $tokenResponse->getAccessToken();
            $refreshToken = $tokenResponse->getRefreshToken();
            $expiresIn = $tokenResponse->getExpiresIn();
            
            Log::info('FIC OAuth callback: Tokens received from FIC', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'team_id' => $team->id,
                'expires_in' => $expiresIn,
                'has_access_token' => !empty($accessToken),
                'has_refresh_token' => !empty($refreshToken),
                'access_token_preview' => $accessToken ? substr($accessToken, 0, 20) . '...' : null,
            ]);
            
            // Calculate token expiration
            $expiresAt = now()->addSeconds($expiresIn);
            
            // Clear the state from Redis (already extracted user_id and tenant_id)
            try {
                Redis::del(self::REDIS_KEY_PREFIX . 'state:' . $receivedState);
            } catch (\Exception $e) {
                Log::warning('FIC OAuth: Failed to clear state from Redis', [
                    'error' => $e->getMessage(),
                ]);
            }
            
            Log::info('FIC OAuth: Tokens obtained successfully', [
                'expires_in' => $expiresIn,
            ]);

            // Create or update FicAccount in database
            try {
                Log::info('FIC OAuth: Attempting to create/update account in database', [
                    'has_access_token' => !empty($accessToken),
                    'has_refresh_token' => !empty($refreshToken),
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                ]);

                // Load user from database using the user_id from state
                $user = \App\Models\User::find($userId);
                
                if (!$user) {
                    Log::error('FIC OAuth: User not found', ['user_id' => $userId]);
                    return redirect('/')->with('error', 'Utente non trovato. Riprova la connessione.');
                }

                $account = $this->createOrUpdateFicAccount(
                    $accessToken,
                    $refreshToken,
                    $expiresAt,
                    $tenantId
                );

                Log::info('FIC OAuth: Account created/updated in database', [
                    'account_id' => $account->id,
                    'company_id' => $account->company_id,
                    'company_name' => $account->company_name,
                    'tenant_id' => $account->tenant_id,
                ]);

                // Clear FIC connection cache for the user
                if ($user) {
                    app(\App\Services\FicConnectionService::class)->clearCache($user);
                }

                return redirect()
                    ->route('dashboard')
                    ->with('banner', "Connessione a Fatture in Cloud completata con successo! Account: {$account->company_name}")
                    ->with('bannerStyle', 'success');
            } catch (\Exception $e) {
                Log::error('FIC OAuth: Failed to create/update account in database', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Still redirect but show warning
                return redirect()
                    ->route('dashboard')
                    ->with('banner', 'Autorizzazione completata, ma errore nel salvataggio account. Controlla i log.')
                    ->with('bannerStyle', 'danger');
            }
            
        } catch (\Exception $e) {
            Log::error('FIC OAuth callback error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            
            return redirect('/')->with('error', 'Errore durante l\'ottenimento dei token: ' . $e->getMessage());
        }
    }

    /**
     * Check OAuth token status for current user's team.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'error' => 'Not authenticated',
            ], 401);
        }
        
        // Get FIC account for current team
        $account = FicAccount::forTeam($user->current_team_id)
            ->first();
        
        if (!$account) {
            return response()->json([
                'connected' => false,
                'message' => 'No FIC account found for this team',
                'team_id' => $user->current_team_id,
            ]);
        }
        
        return response()->json([
            'connected' => !$account->needsReauth(),
            'account' => [
                'id' => $account->id,
                'company_id' => $account->company_id,
                'company_name' => $account->company_name,
                'status' => $account->status,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                'token_expired' => $account->isTokenExpired(),
                'needs_reauth' => $account->needsReauth(),
            ],
            'team_id' => $user->current_team_id,
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
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utente non autenticato.',
            ], 401);
        }
        
        // Get FIC account for current team
        $account = FicAccount::forTeam($user->current_team_id)
            ->active()
            ->first();
        
        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Nessun account FIC attivo per questo team. Esegui prima il flusso OAuth.',
                'team_id' => $user->current_team_id,
            ], 401);
        }
        
        $accessToken = $account->access_token;
        
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
     * @param int|string|null $tenantId Team/Tenant ID to associate the account with
     * @return FicAccount
     * @throws \Exception
     */
    private function createOrUpdateFicAccount(
        string $accessToken,
        string $refreshToken,
        \Carbon\Carbon $expiresAt,
        $tenantId = null
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

        // Find existing account by company_id AND tenant_id, or create new one
        // This allows the same FIC company to be connected to multiple teams
        Log::debug('FIC OAuth: Looking for existing account or creating new one', [
            'company_id' => $companyId,
            'tenant_id' => $tenantId,
        ]);

        $account = FicAccount::updateOrCreate(
            [
                'company_id' => $companyId,
                'tenant_id' => $tenantId,
            ],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $expiresAt,
                'token_refreshed_at' => now(),
                'company_name' => $companyName,
                'company_email' => $companyEmail,
                'status' => 'active',
                'connected_at' => now(),
                'name' => $companyName ?? "Account {$companyId}",
            ]
        );

        $isNew = $account->wasRecentlyCreated;

        Log::info('FIC OAuth: Account saved successfully', [
            'account_id' => $account->id,
            'was_new' => $isNew,
            'company_id' => $account->company_id,
            'tenant_id' => $account->tenant_id,
        ]);

        return $account;
    }
}
