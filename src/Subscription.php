<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon as IlluminateCarbon;
use OrcaRail\Cashier\Exceptions\SubscriptionUpdateFailure;
use OrcaRail\OrcaRailObject;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $type
 * @property string $orcarail_id
 * @property string $orcarail_status
 * @property string|null $orcarail_price_id
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model $billable
 */
class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('billable');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->owner();
    }

    public function active(): bool
    {
        return (is_null($this->ends_at) || $this->onGracePeriod())
            && $this->orcarail_status !== 'canceled'
            && $this->orcarail_status !== 'completed'
            && (!$this->incomplete() || $this->onTrial());
    }

    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    public function incomplete(): bool
    {
        return in_array($this->orcarail_status, ['incomplete', 'requires_payment_method'], true);
    }

    public function pastDue(): bool
    {
        return $this->orcarail_status === 'past_due';
    }

    public function paused(): bool
    {
        return $this->orcarail_status === 'paused';
    }

    public function canceled(): bool
    {
        return !is_null($this->ends_at) || $this->orcarail_status === 'canceled';
    }

    public function ended(): bool
    {
        return $this->canceled() && !$this->onGracePeriod();
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Cancel the subscription immediately via the OrcaRail API.
     *
     * @param  array<string, mixed>  $params
     */
    public function cancel(array $params = []): self
    {
        $remote = Cashier::api()->subscriptions->cancel($this->orcarail_id, $params);

        return $this->syncOrcaRailStatus($remote);
    }

    /**
     * Cancel the subscription at the end of the current period when supported.
     *
     * @param  array<string, mixed>  $params
     */
    public function cancelAtPeriodEnd(array $params = []): self
    {
        $params = array_merge(['cancel_at_period_end' => true], $params);

        $remote = Cashier::api()->subscriptions->update($this->orcarail_id, $params);

        return $this->syncOrcaRailStatus($remote);
    }

    /**
     * Resume a canceled or paused subscription.
     */
    public function resume(): self
    {
        if ($this->orcarail_status === 'canceled' && $this->ended()) {
            throw SubscriptionUpdateFailure::cannotResumeEnded($this);
        }

        $remote = Cashier::api()->subscriptions->resume($this->orcarail_id);

        return $this->syncOrcaRailStatus($remote);
    }

    /**
     * List payment links for this subscription from the API.
     *
     * @param  array<string, mixed>  $params
     */
    public function paymentLinks(array $params = []): OrcaRailObject
    {
        return Cashier::api()->subscriptions->listPaymentLinks($this->orcarail_id, $params);
    }

    /**
     * Refresh local columns from a remote OrcaRail subscription object or payload.
     *
     * @param  OrcaRailObject|array<string, mixed>|null  $remote
     */
    public function syncOrcaRailStatus(OrcaRailObject|array|null $remote = null): self
    {
        if ($remote === null) {
            $remote = Cashier::api()->subscriptions->retrieve($this->orcarail_id);
        }

        $data = $remote instanceof OrcaRailObject ? $remote->toArray() : $remote;

        $status = (string) ($data['status'] ?? $this->orcarail_status);
        $this->orcarail_status = $status;

        if (isset($data['price_id']) && is_string($data['price_id'])) {
            $this->orcarail_price_id = $data['price_id'];
        } elseif (isset($data['items']) && is_array($data['items'])) {
            // leave price as-is when API does not return price_id
        }

        $trialEnd = $data['trial_end'] ?? null;
        $this->trial_ends_at = is_string($trialEnd) && $trialEnd !== ''
            ? IlluminateCarbon::parse($trialEnd)
            : null;

        $endedAt = $data['ended_at'] ?? null;
        $canceledAt = $data['canceled_at'] ?? null;
        $cancelAt = $data['cancel_at'] ?? null;

        if ($status === 'canceled' || $status === 'completed') {
            $this->ends_at = is_string($endedAt) && $endedAt !== ''
                ? IlluminateCarbon::parse($endedAt)
                : (is_string($canceledAt) && $canceledAt !== ''
                    ? IlluminateCarbon::parse($canceledAt)
                    : ($this->ends_at ?? IlluminateCarbon::now()));
        } elseif (!empty($data['cancel_at_period_end']) && is_string($cancelAt) && $cancelAt !== '') {
            $this->ends_at = IlluminateCarbon::parse($cancelAt);
        } elseif ($status !== 'canceled' && $status !== 'completed') {
            $this->ends_at = null;
        }

        $this->save();

        return $this;
    }

    /**
     * Resolve a hosted pay URL from the remote subscription when available.
     */
    public function latestCheckoutUrl(): ?string
    {
        $remote = Cashier::api()->subscriptions->retrieve($this->orcarail_id);
        $link = $remote->latest_payment_link;

        if ($link instanceof OrcaRailObject && is_string($link->link) && $link->link !== '') {
            return $link->link;
        }

        if (is_array($link) && isset($link['link']) && is_string($link['link']) && $link['link'] !== '') {
            return $link['link'];
        }

        return null;
    }
}
