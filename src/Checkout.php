<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class Checkout implements Responsable
{
    public function __construct(
        public readonly ?string $url,
        public readonly ?Subscription $subscription = null,
        public readonly ?Payment $payment = null,
    ) {}

    public static function forSubscription(Subscription $subscription, ?string $url): self
    {
        return new self($url, $subscription, null);
    }

    public static function forPayment(Payment $payment, ?string $url): self
    {
        return new self($url, null, $payment);
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function redirect(): RedirectResponse
    {
        if ($this->url === null || $this->url === '') {
            throw new \RuntimeException(
                'No hosted pay URL is available yet. The subscription may be trialing, or confirm the payment intent first.',
            );
        }

        return redirect()->away($this->url);
    }

    public function toResponse($request): Response
    {
        return $this->redirect();
    }
}
