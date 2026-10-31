<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use OrcaRail\OrcaRailClient;

final class Cashier
{
    /**
     * The Cashier library version.
     */
    public const VERSION = '1.3.0';

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
     * Finish a one-off checkout from your return route (the `payment_intent` query
     * parameter). Required: OrcaRail keeps a paid intent `processing` until it is
     * completed; until then the payment is not final, never withdrawn and no
     * `payment_intent.completed` webhook is sent. The intent completes once the
     * payment is confirmed on-chain; while it is still `processing`, call this again.
     */
    public static function completePayment(string $paymentIntentId): Payment
    {
        return new Payment(self::api()->paymentIntents->complete($paymentIntentId));
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
