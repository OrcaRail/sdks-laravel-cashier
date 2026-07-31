<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use OrcaRail\Cashier\Cashier;
use OrcaRail\Cashier\Events\WebhookHandled;
use OrcaRail\Cashier\Events\WebhookReceived;
use OrcaRail\Cashier\Subscription;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        WebhookReceived::dispatch($payload);

        $method = 'handle' . Str::studly(str_replace('.', '_', (string) ($payload['type'] ?? '')));

        if (method_exists($this, $method)) {
            $this->{$method}($payload);
            WebhookHandled::dispatch($payload);

            return new Response('Webhook Handled', 200);
        }

        return $this->missingMethod($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCanceled(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionPaused(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionResumed(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionPastDue(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCompleted(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionTrialWillEnd(array $payload): void
    {
        $this->syncSubscriptionFromPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function syncSubscriptionFromPayload(array $payload): void
    {
        $data = $this->extractEventObject($payload);
        if ($data === null) {
            return;
        }

        $orcarailId = isset($data['id']) && is_string($data['id']) ? $data['id'] : null;
        if ($orcarailId === null) {
            return;
        }

        /** @var class-string<Subscription> $model */
        $model = Cashier::$subscriptionModel;

        /** @var Subscription|null $subscription */
        $subscription = $model::query()->where('orcarail_id', $orcarailId)->first();

        if ($subscription === null) {
            $subscription = $this->createLocalSubscriptionFromWebhook($data);
            if ($subscription === null) {
                return;
            }
        }

        $subscription->syncOrcaRailStatus($data);
    }

    /**
     * OrcaRail webhooks nest the resource under data.object (Stripe-style).
     * Fall back to a flat data object for older/test payloads.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function extractEventObject(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $object = $data['object'] ?? null;
        if (is_array($object)) {
            return $object;
        }

        // Flat data shape (no nested object).
        if (isset($data['id'])) {
            return $data;
        }

        return null;
    }

    /**
     * Create a local subscription row when metadata includes billable identity.
     *
     * @param  array<string, mixed>  $data
     */
    protected function createLocalSubscriptionFromWebhook(array $data): ?Subscription
    {
        $metadata = $data['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        $billableType = $metadata['billable_type'] ?? null;
        $billableId = $metadata['billable_id'] ?? null;
        $type = isset($metadata['subscription_type']) && is_string($metadata['subscription_type'])
            ? $metadata['subscription_type']
            : 'default';

        if (!is_string($billableType) || $billableType === '' || $billableId === null || $billableId === '') {
            return null;
        }

        $trialEnd = $data['trial_end'] ?? null;

        /** @var class-string<Subscription> $model */
        $model = Cashier::$subscriptionModel;

        return $model::query()->create([
            'billable_type' => $billableType,
            'billable_id' => $billableId,
            'type' => $type,
            'orcarail_id' => (string) $data['id'],
            'orcarail_status' => (string) ($data['status'] ?? 'incomplete'),
            'orcarail_price_id' => isset($data['price_id']) && is_string($data['price_id'])
                ? $data['price_id']
                : null,
            'trial_ends_at' => is_string($trialEnd) && $trialEnd !== '' ? Carbon::parse($trialEnd) : null,
            'ends_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function missingMethod(array $payload = []): Response
    {
        return new Response('Webhook Received', 200);
    }
}
