<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Feature;

use OrcaRail\Cashier\Checkout;
use OrcaRail\Cashier\Exceptions\IncompletePayment;
use OrcaRail\Cashier\Tests\TestCase;

final class CheckoutTest extends TestCase
{
    public function test_checkout_creates_and_confirms_payment_intent(): void
    {
        $user = $this->createUser();

        $this->fakeHttp->respondWith(function (string $method, string $path, ?array $body): array {
            if ($method === 'POST' && $path === 'payment_intents') {
                return [
                    'id' => 'pi_123',
                    'status' => 'requires_payment_method',
                    'clientSecret' => 'pi_123_secret_abc',
                    'client_secret' => 'pi_123_secret_abc',
                ];
            }

            if ($method === 'POST' && str_contains($path, '/confirm')) {
                return [
                    'id' => 'pi_123',
                    'status' => 'requires_confirmation',
                    'clientSecret' => 'pi_123_secret_abc',
                    'pay_url' => 'https://pay.orcarail.com/pay/pl_xyz',
                    'next_action' => [
                        'type' => 'redirect_to_url',
                        'redirect_to_url' => [
                            'url' => 'https://pay.orcarail.com/pay/pl_xyz?payment_intent=pi_123',
                        ],
                    ],
                ];
            }

            return [];
        });

        $checkout = $user->checkout('price_abc', [
            'return_url' => 'https://merchant.test/return',
        ]);

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertSame(
            'https://pay.orcarail.com/pay/pl_xyz?payment_intent=pi_123',
            $checkout->url(),
        );
        $this->assertNotNull($checkout->payment);
        $this->assertSame('pi_123', $checkout->payment->id());
        $this->assertCount(2, $this->fakeHttp->requests);
    }

    public function test_checkout_throws_when_no_redirect_url(): void
    {
        $user = $this->createUser();

        $this->fakeHttp->respondWith([
            'id' => 'pi_no_url',
            'status' => 'requires_payment_method',
            'clientSecret' => null,
        ]);

        $this->expectException(IncompletePayment::class);

        $user->checkout([
            'amount' => '10.00',
            'currency' => 'usd',
            'tokenId' => 'tok_1',
            'networkId' => 'net_1',
        ]);
    }
}
