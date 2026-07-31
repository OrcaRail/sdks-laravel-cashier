<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use OrcaRail\OrcaRailObject;

final class SubscriptionBuilder
{
    protected Model $owner;

    protected string $type;

    protected ?string $priceId = null;

    /** @var array<string, mixed> */
    protected array $directAmount = [];

    protected ?int $trialDays = null;

    protected ?Carbon $trialUntil = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    public function __construct(Model $owner, string $type, ?string $priceId = null)
    {
        $this->owner = $owner;
        $this->type = $type;
        $this->priceId = $priceId;
    }

    public function price(string $priceId): self
    {
        $this->priceId = $priceId;
        $this->directAmount = [];

        return $this;
    }

    /**
     * Create with direct amount parameters instead of a catalog price.
     *
     * @param  array{amount: string, currency: string, token_id: string, network_id: string, interval: string}  $params
     */
    public function withAmount(array $params): self
    {
        $this->directAmount = $params;
        $this->priceId = null;

        return $this;
    }

    public function trialDays(int $trialDays): self
    {
        $this->trialDays = $trialDays;
        $this->trialUntil = null;

        return $this;
    }

    public function trialUntil(Carbon|\DateTimeInterface|string $date): self
    {
        $this->trialUntil = Carbon::parse($date);
        $this->trialDays = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create the OrcaRail subscription, persist a local row, and return a Checkout redirect.
     *
     * @param  array<string, mixed>  $options
     */
    public function create(array $options = []): Checkout
    {
        $payload = $this->buildPayload($options);

        $remote = Cashier::api()->subscriptions->create($payload);

        $subscription = $this->storeLocalSubscription($remote);

        $url = $this->resolveCheckoutUrl($remote);

        return Checkout::forSubscription($subscription, $url);
    }

    /**
     * Alias for create() — always returns a Checkout for the hosted pay page.
     *
     * @param  array<string, mixed>  $options
     */
    public function checkout(array $options = []): Checkout
    {
        return $this->create($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $options): array
    {
        $payload = array_merge($this->options, $options);

        if ($this->priceId !== null) {
            $payload['price_id'] = $this->priceId;
        } else {
            $payload = array_merge($this->directAmount, $payload);
        }

        if ($this->trialDays !== null) {
            $payload['trial_period_days'] = $this->trialDays;
        } elseif ($this->trialUntil !== null) {
            $payload['trial_end'] = $this->trialUntil->toIso8601String();
        }

        if ($this->metadata !== []) {
            $payload['metadata'] = array_merge(
                is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                $this->metadata,
                [
                    'billable_type' => $this->owner->getMorphClass(),
                    'billable_id' => (string) $this->owner->getKey(),
                    'subscription_type' => $this->type,
                ],
            );
        } else {
            $payload['metadata'] = array_merge(
                is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                [
                    'billable_type' => $this->owner->getMorphClass(),
                    'billable_id' => (string) $this->owner->getKey(),
                    'subscription_type' => $this->type,
                ],
            );
        }

        if (!isset($payload['payer_email']) && isset($this->owner->email) && is_string($this->owner->email)) {
            $payload['payer_email'] = $this->owner->email;
        }

        return $payload;
    }

    private function storeLocalSubscription(OrcaRailObject $remote): Subscription
    {
        /** @var class-string<Subscription> $model */
        $model = Cashier::$subscriptionModel;

        $trialEnd = $remote->trial_end;
        $status = (string) ($remote->status ?? 'incomplete');
        $priceId = is_string($remote->price_id) ? $remote->price_id : $this->priceId;

        /** @var Subscription $subscription */
        $subscription = $model::query()->create([
            'billable_type' => $this->owner->getMorphClass(),
            'billable_id' => $this->owner->getKey(),
            'type' => $this->type,
            'orcarail_id' => (string) $remote->id,
            'orcarail_status' => $status,
            'orcarail_price_id' => $priceId,
            'trial_ends_at' => is_string($trialEnd) && $trialEnd !== '' ? Carbon::parse($trialEnd) : null,
            'ends_at' => null,
        ]);

        return $subscription;
    }

    private function resolveCheckoutUrl(OrcaRailObject $remote): ?string
    {
        $link = $remote->latest_payment_link;

        if ($link instanceof OrcaRailObject && is_string($link->link) && $link->link !== '') {
            return $link->link;
        }

        if (is_array($link) && isset($link['link']) && is_string($link['link'])) {
            return $link['link'];
        }

        // Trialing subscriptions may not have a payment link yet.
        return null;
    }
}
