<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Exceptions;

use Exception;
use OrcaRail\Cashier\Payment;

class IncompletePayment extends Exception
{
    public function __construct(
        public readonly Payment $payment,
        string $message = 'The payment requires a hosted pay redirect before it can be completed.',
    ) {
        parent::__construct($message);
    }

    public static function forPayment(Payment $payment): self
    {
        return new self($payment);
    }
}
