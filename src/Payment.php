<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use OrcaRail\OrcaRailObject;

final class Payment
{
    public function __construct(
        public readonly OrcaRailObject $intent,
    ) {}

    public function id(): string
    {
        return (string) $this->intent->id;
    }

    public function status(): string
    {
        return (string) ($this->intent->status ?? '');
    }

    public function clientSecret(): ?string
    {
        $secret = $this->intent->clientSecret ?? $this->intent->client_secret;

        return is_string($secret) ? $secret : null;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status(), ['completed', 'succeeded'], true);
    }

    public function isCanceled(): bool
    {
        return in_array($this->status(), ['canceled', 'cancelled'], true);
    }

    public function requiresAction(): bool
    {
        return in_array($this->status(), [
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
        ], true);
    }

    /**
     * Resolve the hosted pay redirect URL from a create or confirm response.
     */
    public function checkoutUrl(): ?string
    {
        $intent = $this->intent;

        $nextAction = $intent->nextAction ?? $intent->next_action ?? null;
        if ($nextAction instanceof OrcaRailObject) {
            $redirect = $nextAction->redirectToUrl ?? $nextAction->redirect_to_url ?? null;
            if ($redirect instanceof OrcaRailObject && is_string($redirect->url) && $redirect->url !== '') {
                return $redirect->url;
            }
            if (is_array($redirect) && isset($redirect['url']) && is_string($redirect['url'])) {
                return $redirect['url'];
            }
        } elseif (is_array($nextAction)) {
            $redirect = $nextAction['redirectToUrl'] ?? $nextAction['redirect_to_url'] ?? null;
            if (is_array($redirect) && isset($redirect['url']) && is_string($redirect['url'])) {
                return $redirect['url'];
            }
        }

        $payUrl = $intent->pay_url ?? null;
        if (is_string($payUrl) && $payUrl !== '') {
            return $payUrl;
        }

        $paymentLink = $intent->paymentLink ?? $intent->payment_link ?? null;
        if ($paymentLink instanceof OrcaRailObject && is_string($paymentLink->link) && $paymentLink->link !== '') {
            return $paymentLink->link;
        }
        if (is_array($paymentLink) && isset($paymentLink['link']) && is_string($paymentLink['link'])) {
            return $paymentLink['link'];
        }

        return null;
    }

    public function asOrcaRailObject(): OrcaRailObject
    {
        return $this->intent;
    }
}
