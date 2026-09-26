<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use OrcaRail\OrcaRailClient;

final class Cashier
{
    /**
     * The Cashier library version.
     */
    public const VERSION = '1.0.0';

    /**
     * The custom subscription model class name.
     *
     * @var class-string<\OrcaRail\Cashier\Subscription>
     */
    public static string $subscriptionModel = Subscription::class;

    /**
     * Indicates if Cashier migrations will be run.
     */
    public static bool $runsMigrations = true;

    /**
     * Indicates if Cashier routes will be registered.
     */
    public static bool $registersRoutes = true;

    /**
     * Get the OrcaRail API client from the container.
     */
    public static function api(): OrcaRailClient
    {
        return app(OrcaRailClient::class);
    }

    /**
     * False when Cashier uses a sandbox organization key (ak_test_…): testnets
     * only, no real funds. The API decides the mode from the key's organization;
     * this lets your app tell test payments apart (e.g. never ship real goods).
     */
    public static function livemode(): bool
    {
        return !str_starts_with(trim((string) config('orcarail-cashier.api_key')), 'ak_test_');
    }

    /**
     * Set the subscription model used by Cashier.
     *
     * @param  class-string<\OrcaRail\Cashier\Subscription>  $model
     */
    public static function useSubscriptionModel(string $model): void
    {
        static::$subscriptionModel = $model;
    }

    /**
     * Configure Cashier to not register its migrations.
     */
    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
    }

    /**
     * Configure Cashier to not register its routes.
     */
    public static function ignoreRoutes(): void
    {
        static::$registersRoutes = false;
    }
}
