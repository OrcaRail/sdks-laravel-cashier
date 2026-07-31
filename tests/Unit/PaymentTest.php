<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Unit;

use OrcaRail\Cashier\Payment;
use OrcaRail\Cashier\Tests\TestCase;
use OrcaRail\OrcaRailObject;

final class PaymentTest extends TestCase
{
    public function test_checkout_url_prefers_next_action_redirect(): void
    {
        $payment = new Payment(new OrcaRailObject([
            'id' => 'pi_1',
            'status' => 'requires_confirmation',
            'pay_url' => 'https://pay.orcarail.com/pay/clean',
            'next_action' => [
                'type' => 'redirect_to_url',
                'redirect_to_url' => [
                    'url' => 'https://pay.orcarail.com/pay/with-secret',
                ],
            ],
        ]));

        // next_action redirect is preferred over the clean pay_url.
        $this->assertSame('https://pay.orcarail.com/pay/with-secret', $payment->checkoutUrl());
        $this->assertTrue($payment->requiresAction());
        $this->assertFalse($payment->isCompleted());
    }
}
