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
    
    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Fatture in Cloud webhook verification.
    |
    */
    
    /*
    | Webhook Public Key
    |
    | The public key (base64 encoded) provided by Fatture in Cloud
    | for verifying JWT signatures in webhook notifications.
    | You can find this in your Fatture in Cloud app settings.
    |
    | Default public key from FIC documentation:
    | LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUZrd0V3WUhLb1pJemowQ0FRWUlLb1pJemowREFRY0RRZ0FFL1JvSElqZ1k3aGZYZlk1cC9KeStLL0ZndU1aNAozVHZaOXQ0ZU43K2t4UTBNSnpLdG93djRDY1lURnFyQm03aE1CNVpXS25xTHoyNEQ2bFFqU0wwWXN3PT0KLS0tLS1FTkQgUFVCTElDIEtFWS0tLS0tCg==
    |
    */
    'webhook_public_key' => env('FIC_WEBHOOK_PUBLIC_KEY', 'LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUZrd0V3WUhLb1pJemowQ0FRWUlLb1pJemowREFRY0RRZ0FFL1JvSElqZ1k3aGZYZlk1cC9KeStLL0ZndU1aNAozVHZaOXQ0ZU43K2t4UTBNSnpLdG93djRDY1lURnFyQm03aE1CNVpXS25xTHoyNEQ2bFFqU0wwWXN3PT0KLS0tLS1FTkQgUFVCTElDIEtFWS0tLS0tCg=='),
    
    /*
    | Webhook URL
    |
    | The URL where Fatture in Cloud will send webhook notifications.
    | This should match the URL configured in your FIC app settings.
    |
    */
    'webhook_url' => env('FIC_WEBHOOK_URL', env('APP_URL') . '/api/webhooks/fattureincloud'),
    
    /*
    | Webhook JWT Verification
    |
    | Enable or disable JWT signature verification for webhook notifications.
    | Set to false to disable verification during development/testing.
    | Should be enabled in production for security.
    |
    */
    'webhook_verify_jwt' => env('FIC_WEBHOOK_VERIFY_JWT', true),
];