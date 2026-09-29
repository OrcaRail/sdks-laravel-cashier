<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Unit;

use OrcaRail\Cashier\Cashier;
use OrcaRail\Cashier\Tests\TestCase;

final class LivemodeTest extends TestCase
{
    public function test_sandbox_keys_are_not_livemode(): void
    {
        config(['orcarail-cashier.api_key' => 'ak_test_abc']);
        $this->assertFalse(Cashier::livemode());
    }

    public function test_live_keys_are_livemode(): void
    {
        config(['orcarail-cashier.api_key' => 'ak_live_abc']);
        $this->assertTrue(Cashier::livemode());
    }
}
