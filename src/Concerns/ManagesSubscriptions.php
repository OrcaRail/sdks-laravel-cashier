<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use OrcaRail\Cashier\Cashier;
use OrcaRail\Cashier\Subscription;
use OrcaRail\Cashier\SubscriptionBuilder;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     */
    public function newSubscription(string $type = 'default', ?string $priceId = null): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, $priceId);
    }

    /**
     * Get all of the subscriptions for the billable model.
     *
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        /** @var class-string<Subscription> $model */
        $model = Cashier::$subscriptionModel;

        return $this->morphMany($model, 'billable');
    }

    /**
     * Get a subscription by type (defaults to "default").
     */
    public function subscription(?string $type = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('type', $type ?? 'default')
            ->first();
    }

    /**
     * Determine if the billable model has an active subscription of the given type.
     */
    public function subscribed(?string $type = 'default', ?string $priceId = null): bool
    {
        $subscription = $this->subscription($type);

        if (!$subscription || !$subscription->valid()) {
            return false;
        }

        return $priceId === null || $subscription->orcarail_price_id === $priceId;
    }

    /**
     * Determine if the billable model is on a generic trial for the given subscription type.
     */
    public function onTrial(?string $type = 'default', ?string $priceId = null): bool
    {
        $subscription = $this->subscription($type);

        if (!$subscription || !$subscription->onTrial()) {
            return false;
        }

        return $priceId === null || $subscription->orcarail_price_id === $priceId;
    }

    /**
     * Determine if the billable model has a subscription that is still on its grace period.
     */
    public function onGracePeriod(?string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        return $subscription !== null && $subscription->onGracePeriod();
    }
}
