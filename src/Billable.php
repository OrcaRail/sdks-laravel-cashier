<?php

declare(strict_types=1);

namespace OrcaRail\Cashier;

use OrcaRail\Cashier\Concerns\ManagesSubscriptions;
use OrcaRail\Cashier\Concerns\PerformsCharges;

trait Billable
{
    use ManagesSubscriptions;
    use PerformsCharges;
}
