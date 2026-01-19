<?php

namespace Tests\Unit;

use App\Models\FicAccount;
use App\Models\FicSubscription;
use App\Services\FicApiService;
use FattureInCloud\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class FicApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test SDK initialization with valid access token.
     */
    public function test_initialize_sdk_with_valid_token(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-123',
        ]);

        $service = new FicApiService($account);
        $config = $service->initializeSdk();

        $this->assertInstanceOf(Configuration::class, $config);
    }

    /**
     * Test SDK initialization fails without access token.
     */
    public function test_initialize_sdk_fails_without_token(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => null,
        ]);

        $service = new FicApiService($account);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access token is missing');

        $service->initializeSdk();
    }

    /**
     * Test SDK initialization is cached (returns same instance).
     */
    public function test_initialize_sdk_is_cached(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-456',
        ]);

        $service = new FicApiService($account);
        $config1 = $service->initializeSdk();
        $config2 = $service->initializeSdk();

        $this->assertSame($config1, $config2);
    }

    /**
     * Test creating a new subscription successfully.
     */
    public function test_create_or_renew_subscription_creates_new_subscription(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-789',
            'company_id' => 1234567,
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $responseData = [
            'data' => [
                'id' => 'sub_new_123',
                'secret' => 'webhook-secret-abc123',
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ],
        ];

        $mockResponse = new Response(200, [], json_encode($responseData));

        $service = new FicApiService($account);
        
        // Use reflection to inject a mock HTTP client
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::pattern('/\/subscriptions$/'), Mockery::type('array'))
            ->andReturn($mockResponse);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        
        // Initialize SDK to set config
        $service->initializeSdk();

        $result = $service->createOrRenewSubscription($eventGroup, $webhookUrl);

        $this->assertIsArray($result);
        $this->assertEquals('sub_new_123', $result['id']);
        $this->assertEquals('webhook-secret-abc123', $result['secret']);
        $this->assertNotNull($result['expires_at']);
    }

    /**
     * Test renewing an existing subscription successfully.
     */
    public function test_create_or_renew_subscription_renews_existing_subscription(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-101',
            'company_id' => 1234567,
        ]);

        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $account->id,
            'event_group' => 'entity',
            'fic_subscription_id' => 'sub_existing_456',
            'is_active' => true,
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $responseData = [
            'data' => [
                'id' => 'sub_existing_456',
                'secret' => 'webhook-secret-renewed-xyz789',
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ],
        ];

        $mockResponse = new Response(200, [], json_encode($responseData));

        $service = new FicApiService($account);
        
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->with('PUT', Mockery::pattern('/\/subscriptions\/sub_existing_456$/'), Mockery::type('array'))
            ->andReturn($mockResponse);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        $service->initializeSdk();

        $result = $service->createOrRenewSubscription($eventGroup, $webhookUrl);

        $this->assertIsArray($result);
        $this->assertEquals('sub_existing_456', $result['id']);
        $this->assertEquals('webhook-secret-renewed-xyz789', $result['secret']);
        $this->assertNotNull($result['expires_at']);
    }

    /**
     * Test handling rate limiting (429) error.
     */
    public function test_create_or_renew_subscription_handles_rate_limiting(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-202',
            'company_id' => 1234567,
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $mockRequest = new Request('POST', 'https://api-v2.fattureincloud.it/c/1234567/subscriptions');
        $mockResponse = new Response(429, ['Retry-After' => ['120']], 'Rate limit exceeded');

        $clientException = new ClientException(
            'Rate limit exceeded',
            $mockRequest,
            $mockResponse
        );

        $service = new FicApiService($account);
        
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->andThrow($clientException);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        $service->initializeSdk();

        Log::shouldReceive('warning')
            ->once()
            ->with('FIC API: Rate limit exceeded', Mockery::type('array'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        $this->expectExceptionCode(429);

        $service->createOrRenewSubscription($eventGroup, $webhookUrl);
    }

    /**
     * Test handling unauthorized (401) error - credentials expired.
     */
    public function test_create_or_renew_subscription_handles_unauthorized(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-303',
            'company_id' => 1234567,
            'status' => 'active',
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $mockRequest = new Request('POST', 'https://api-v2.fattureincloud.it/c/1234567/subscriptions');
        $mockResponse = new Response(401, [], 'Unauthorized');

        $clientException = new ClientException(
            'Unauthorized',
            $mockRequest,
            $mockResponse
        );

        $service = new FicApiService($account);
        
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->andThrow($clientException);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        $service->initializeSdk();

        Log::shouldReceive('error')
            ->once()
            ->with('FIC API: Unauthorized - credentials may be expired', Mockery::type('array'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication failed');
        $this->expectExceptionCode(401);

        $service->createOrRenewSubscription($eventGroup, $webhookUrl);

        // Verify account was marked as needing refresh
        $account->refresh();
        $this->assertEquals('needs_refresh', $account->status);
        $this->assertEquals('Access token expired or invalid', $account->status_note);
    }

    /**
     * Test handling not found (404) error.
     */
    public function test_create_or_renew_subscription_handles_not_found(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-404',
            'company_id' => 1234567,
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $mockRequest = new Request('POST', 'https://api-v2.fattureincloud.it/c/1234567/subscriptions');
        $mockResponse = new Response(404, [], json_encode(['error' => 'Not found']));

        $clientException = new ClientException(
            'Not found',
            $mockRequest,
            $mockResponse
        );

        $service = new FicApiService($account);
        
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->andThrow($clientException);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        $service->initializeSdk();

        Log::shouldReceive('error')
            ->once()
            ->with('FIC API: Client error creating/renewing subscription', Mockery::type('array'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FIC API error (HTTP 404)');
        $this->expectExceptionCode(404);

        $service->createOrRenewSubscription($eventGroup, $webhookUrl);
    }

    /**
     * Test handling server error (500).
     */
    public function test_create_or_renew_subscription_handles_server_error(): void
    {
        $account = FicAccount::factory()->create([
            'access_token' => 'test-access-token-500',
            'company_id' => 1234567,
        ]);

        $webhookUrl = 'https://example.com/api/webhooks/fic/1/entity';
        $eventGroup = 'entity';

        $mockRequest = new Request('POST', 'https://api-v2.fattureincloud.it/c/1234567/subscriptions');
        $mockResponse = new Response(500, [], 'Internal Server Error');

        $serverException = new ServerException(
            'Internal Server Error',
            $mockRequest,
            $mockResponse
        );

        $service = new FicApiService($account);
        
        $reflection = new \ReflectionClass($service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        
        $mockHttpClient = Mockery::mock(Client::class);
        $mockHttpClient->shouldReceive('request')
            ->once()
            ->andThrow($serverException);
        
        $httpClientProperty->setValue($service, $mockHttpClient);
        $service->initializeSdk();

        Log::shouldReceive('error')
            ->once()
            ->with('FIC API: Server error creating/renewing subscription', Mockery::type('array'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FIC API server error (HTTP 500)');
        $this->expectExceptionCode(500);

        $service->createOrRenewSubscription($eventGroup, $webhookUrl);
    }
}
