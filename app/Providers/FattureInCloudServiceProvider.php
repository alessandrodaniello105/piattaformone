<?php

namespace App\Providers;

use FattureInCloud\Configuration;
use FattureInCloud\OAuth2\OAuth2AuthorizationCode\OAuth2AuthorizationCodeManager;
use Illuminate\Support\ServiceProvider;

class FattureInCloudServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register OAuth Manager as singleton
        $this->app->singleton(OAuth2AuthorizationCodeManager::class, function ($app) {
            $config = config('fattureincloud');
            
            return new OAuth2AuthorizationCodeManager(
                $config['client_id'],
                $config['client_secret'],
                $config['redirect_uri']
            );
        });

        // Register Configuration instance as singleton
        $this->app->singleton('fattureincloud.config', function ($app) {
            $config = Configuration::getDefaultConfiguration();
            
            $accessToken = config('fattureincloud.access_token');
            if ($accessToken) {
                $config->setAccessToken($accessToken);
            }
            
            return $config;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
