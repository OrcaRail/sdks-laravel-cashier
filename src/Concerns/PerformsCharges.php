<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Concerns;

use OrcaRail\Cashier\Cashier;
use OrcaRail\Cashier\Checkout;
use OrcaRail\Cashier\Exceptions\IncompletePayment;
use OrcaRail\Cashier\Payment;
use OrcaRail\OrcaRailObject;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait PerformsCharges
{
    /**
     * Create a one-off payment intent and return a hosted Checkout redirect.
     *
     * Pass either a catalog `price_id` string, or an options array with
     * amount/currency/tokenId/networkId (or price_id).
     *
     * @param  string|array<string, mixed>  $amountOrOptions
     * @param  array<string, mixed>  $options
     */
    public function checkout(string|array $amountOrOptions, array $options = []): Checkout
    {
        $payment = $this->createPayment($amountOrOptions, $options);

        $url = $payment->checkoutUrl();

        if ($url === null) {
            $url = $this->confirmPaymentForCheckout($payment, $options)->checkoutUrl();
        }

        if ($url === null) {
            throw IncompletePayment::forPayment($payment);
        }

        return Checkout::forPayment($payment, $url);
    }

    /**
     * Alias for checkout() when charging a direct amount payload.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function charge(array $params, array $options = []): Checkout
    {
        return $this->checkout(array_merge($params, $options));
    }

    /**
     * Create a payment intent without confirming / redirecting.
     *
     * @param  string|array<string, mixed>  $amountOrOptions
     * @param  array<string, mixed>  $options
     */
    public function createPayment(string|array $amountOrOptions, array $options = []): Payment
    {
        $params = is_string($amountOrOptions)
            ? array_merge(['price_id' => $amountOrOptions], $options)
            : array_merge($amountOrOptions, $options);

        if (!isset($params['metadata']) || !is_array($params['metadata'])) {
            $params['metadata'] = [];
        }

        $params['metadata'] = array_merge($params['metadata'], [
            'billable_type' => $this->getMorphClass(),
            'billable_id' => (string) $this->getKey(),
        ]);

        $hasPrice = isset($params['price_id']) && is_string($params['price_id']) && $params['price_id'] !== '';
        if (!$hasPrice && !isset($params['currency'])) {
            $params['currency'] = (string) config('orcarail-cashier.currency', 'usd');
        }

        $intent = Cashier::api()->paymentIntents->create($params);

        return new Payment($intent);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function confirmPaymentForCheckout(Payment $payment, array $options): Payment
    {
        $clientSecret = $payment->clientSecret();
        if ($clientSecret === null || $clientSecret === '') {
            return $payment;
        }

        $confirmParams = [
            'client_secret' => $clientSecret,
            'payment_method_data' => ['type' => 'crypto'],
        ];

        if (isset($options['return_url']) && is_string($options['return_url'])) {
            $confirmParams['return_url'] = $options['return_url'];
        }

        /** @var OrcaRailObject $confirmed */
        $confirmed = Cashier::api()->paymentIntents->confirm($payment->id(), $confirmParams);

        return new Payment($confirmed);
    }
}
