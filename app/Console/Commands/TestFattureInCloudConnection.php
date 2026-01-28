<?php

namespace App\Console\Commands;

use FattureInCloud\Api\UserApi;
use FattureInCloud\Configuration;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class TestFattureInCloudConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fic:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the connection to Fatture in Cloud API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Testing Fatture in Cloud connection...');
        $this->newLine();

        // Check configuration
        $config = config('fattureincloud');
        
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            $this->error('❌ Configuration error: FIC_CLIENT_ID and FIC_CLIENT_SECRET must be set in .env');
            return Command::FAILURE;
        }

        $this->info('✓ Configuration found');
        $this->line("  Client ID: {$config['client_id']}");
        $redirectUri = $config['redirect_uri'] ?? 'Not set';
        
        if ($redirectUri === 'Not set' || empty($redirectUri)) {
            $this->newLine();
            $this->error('❌ FIC_REDIRECT_URI is not set in .env!');
            $this->line('  This must match EXACTLY the redirect URI registered in your Fatture in Cloud app.');
            $this->line('  Example: http://localhost:8080/api/fic/oauth/callback');
            return Command::FAILURE;
        }
        
        $this->line("  Redirect URI: {$redirectUri}");
        $this->line("  Redirect URI length: " . strlen($redirectUri) . " characters");
        
        // Check for common issues
        $issues = [];
        if (str_contains($redirectUri, ' ')) {
            $issues[] = 'Contains spaces';
        }
        if (preg_match('/[^\x20-\x7E]/', $redirectUri)) {
            $issues[] = 'Contains non-printable characters';
        }
        if ($redirectUri !== trim($redirectUri)) {
            $issues[] = 'Has leading/trailing whitespace';
        }
        
        if (!empty($issues)) {
            $this->newLine();
            $this->warn('⚠️  Potential issues detected:');
            foreach ($issues as $issue) {
                $this->line("  - {$issue}");
            }
        }
        
        $this->newLine();
        $this->comment('⚠️  IMPORTANT: The redirect URI above must match EXACTLY');
        $this->comment('   (including protocol, port, path, and trailing slashes)');
        $this->comment('   the URI registered in your Fatture in Cloud app settings.');
        $this->newLine();
        $this->line('  To verify:');
        $this->line('  1. Go to https://developers.fattureincloud.it');
        $this->line('  2. Open your app settings');
        $this->line('  3. Check the Redirect URI field');
        $this->line('  4. It must be EXACTLY: ' . $redirectUri);
        $this->newLine();

        // Check for access token (Redis first, then config)
        $accessToken = null;
        $tokenSource = null;
        
        try {
            $redisToken = Redis::get('fic:oauth:access_token');
            if ($redisToken) {
                $accessToken = $redisToken;
                $tokenSource = 'Redis';
            }
        } catch (\Exception $e) {
            // Redis not available or error, continue to check config
        }
        
        if (!$accessToken) {
            $accessToken = $config['access_token'] ?? null;
            $tokenSource = $accessToken ? 'config (.env)' : null;
        }

        if (!$accessToken) {
            $this->warn('⚠ No access token found.');
            $this->line('  To obtain an access token:');
            
            // Build the OAuth redirect URL with correct port
            $appUrl = config('app.url', 'http://localhost');
            $oauthUrl = rtrim($appUrl, '/') . '/api/fic/oauth/redirect';
            $this->line('  1. Visit: ' . $oauthUrl);
            $this->line('  2. Authorize the application');
            $this->line('  3. The token will be stored in Redis');
            $this->newLine();
            $this->line('  Alternatively, set FIC_ACCESS_TOKEN in your .env file');
            return Command::SUCCESS;
        }
        
        $this->info('✓ Access token found (' . $tokenSource . ')');

        $this->info('✓ Access token found');
        $this->newLine();

        // Test API connection
        try {
            $this->info('Testing API connection...');
            
            $apiConfig = Configuration::getDefaultConfiguration();
            $apiConfig->setAccessToken($accessToken);
            
            $userApi = new UserApi(
                new Client(),
                $apiConfig
            );

            $this->line('  Calling listUserCompanies()...');
            $response = $userApi->listUserCompanies();
            $companiesData = $response->getData();
            $companies = $companiesData ? $companiesData->getCompanies() : [];
            $companiesCount = is_array($companies) ? count($companies) : 0;

            $this->newLine();
            $this->info("✓ Connection successful!");
            $this->line("  Found {$companiesCount} company/companies:");
            $this->newLine();

            if (is_array($companies) && !empty($companies)) {
                foreach ($companies as $index => $company) {
                    $this->line("  " . ($index + 1) . ". {$company->getName()} (ID: {$company->getId()})");
                }

                // Show company ID if only one company
                if ($companiesCount === 1 && empty($config['company_id'])) {
                    $this->newLine();
                    $this->comment('💡 Tip: Set FIC_COMPANY_ID=' . $companies[0]->getId() . ' in your .env file');
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Connection failed!');
            $this->error('  Error: ' . $e->getMessage());
            $this->newLine();
            
            if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'Unauthorized')) {
                $this->warn('  The access token appears to be invalid or expired.');
                $appUrl = config('app.url', 'http://localhost:8080');
                $oauthUrl = rtrim($appUrl, '/') . '/api/fic/oauth/redirect';
                $this->line('  Try running the OAuth flow again: ' . $oauthUrl);
            }

            return Command::FAILURE;
        }
    }
}
