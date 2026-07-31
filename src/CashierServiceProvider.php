<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use Illuminate\Support\ServiceProvider;
use OrcaRail\HttpClient\ClientInterface;
use OrcaRail\OrcaRailClient;

final class CashierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/orcarail-cashier.php',
            'orcarail-cashier',
        );

        $this->app->singleton(OrcaRailClient::class, function ($app): OrcaRailClient {
            $config = $app['config']['orcarail-cashier'];

            $clientConfig = [
                'api_key' => (string) ($config['api_key'] ?? ''),
                'api_secret' => (string) ($config['api_secret'] ?? ''),
                'base_url' => (string) ($config['base_url'] ?? 'https://api.orcarail.com/api/v1'),
                'timeout' => (int) ($config['timeout'] ?? 30000),
                'connect_timeout' => (int) ($config['connect_timeout'] ?? 10000),
            ];

            if ($app->bound(ClientInterface::class)) {
                $clientConfig['http_client'] = $app->make(ClientInterface::class);
            }

            return new OrcaRailClient($clientConfig);
        });
    }

    public function boot(): void
    {
        $this->configurePublishing();
        $this->configureMigrations();
        $this->configureRoutes();
    }

    private function configurePublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/orcarail-cashier.php' => config_path('orcarail-cashier.php'),
        ], 'orcarail-cashier-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'orcarail-cashier-migrations');
    }

    private function configureMigrations(): void
    {
        if (Cashier::$runsMigrations && $this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }

    private function configureRoutes(): void
    {
        if (!Cashier::$registersRoutes) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}
