<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Exceptions;

use Exception;
use OrcaRail\Cashier\Subscription;

class SubscriptionUpdateFailure extends Exception
{
    public static function cannotResumeEnded(Subscription $subscription): self
    {
        return new self(
            "Subscription [{$subscription->orcarail_id}] has ended and cannot be resumed.",
        );
    }
}
