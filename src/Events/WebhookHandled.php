<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WebhookHandled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}
}
