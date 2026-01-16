<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fatture in Cloud Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Fatture in Cloud OAuth2 integration.
    | All credentials should be set in your .env file.
    |
    */

    'client_id' => env('FIC_CLIENT_ID'),
    'client_secret' => env('FIC_CLIENT_SECRET'),
    
    /*
    |--------------------------------------------------------------------------
    | Redirect URI
    |--------------------------------------------------------------------------
    |
    | The callback URL where Fatture in Cloud will redirect after authorization.
    | This must match exactly (including trailing slashes) the URI configured
    | in your Fatture in Cloud app settings.
    |
    | For local development, localhost with explicit port is allowed:
    | Example (dev): http://localhost:8080/api/fic/oauth/callback
    | Example (prod): https://yourdomain.com/api/fic/oauth/callback
    |
    */
    'redirect_uri' => env('FIC_REDIRECT_URI'),
    
    /*
    |--------------------------------------------------------------------------
    | Company ID
    |--------------------------------------------------------------------------
    |
    | The Fatture in Cloud company ID to use for API calls.
    | Currently hardcoded for single-tenant setup.
    | TODO: Implement multi-tenant system to switch between companies.
    |
    */
    'company_id' => 1543167, // Hardcoded for now, will be multi-tenant in future
    
    'access_token' => env('FIC_ACCESS_TOKEN'),
    'refresh_token' => env('FIC_REFRESH_TOKEN'),
];