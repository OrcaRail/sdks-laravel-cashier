<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Feature;

use OrcaRail\Cashier\Checkout;
use OrcaRail\Cashier\Subscription;
use OrcaRail\Cashier\Tests\TestCase;

final class SubscriptionBuilderTest extends TestCase
{
    public function test_create_subscription_persists_local_row_and_returns_checkout(): void
    {
        $user = $this->createUser();

        $this->fakeHttp->respondWith([
            'id' => 'sub_123',
            'status' => 'active',
            'price_id' => 'price_abc',
            'trial_end' => null,
            'latest_payment_link' => [
                'id' => 'pl_1',
                'unique_slug' => 'pl_abc123',
                'link' => 'https://pay.orcarail.com/pay/pl_abc123',
                'status' => 'active',
            ],
        ]);

        $checkout = $user->newSubscription('default', 'price_abc')
            ->create(['payer_email' => $user->email]);

        $this->assertInstanceOf(Checkout::class, $checkout);
        $this->assertSame('https://pay.orcarail.com/pay/pl_abc123', $checkout->url());
        $this->assertInstanceOf(Subscription::class, $checkout->subscription);

        $this->assertDatabaseHas('subscriptions', [
            'billable_id' => $user->id,
            'orcarail_id' => 'sub_123',
            'orcarail_status' => 'active',
            'orcarail_price_id' => 'price_abc',
            'type' => 'default',
        ]);

        $this->assertTrue($user->subscribed());
        $this->assertNotNull($user->subscription());

        $this->assertCount(1, $this->fakeHttp->requests);
        $this->assertSame('POST', $this->fakeHttp->requests[0]['method']);
        $this->assertSame('subscriptions', $this->fakeHttp->requests[0]['path']);
        $this->assertSame('price_abc', $this->fakeHttp->requests[0]['body']['price_id'] ?? null);
        $this->assertSame(
            (string) $user->id,
            $this->fakeHttp->requests[0]['body']['metadata']['billable_id'] ?? null,
        );
    }

    public function test_trial_days_sends_trial_period_days(): void
    {
        $user = $this->createUser();

        $this->fakeHttp->respondWith([
            'id' => 'sub_trial',
            'status' => 'trialing',
            'price_id' => 'price_abc',
            'trial_end' => now()->addDays(14)->toIso8601String(),
            'latest_payment_link' => null,
        ]);

        $checkout = $user->newSubscription('default', 'price_abc')
            ->trialDays(14)
            ->create();

        $this->assertSame(14, $this->fakeHttp->requests[0]['body']['trial_period_days'] ?? null);
        $this->assertNull($checkout->url());
        $this->assertTrue($user->onTrial());
        $this->assertTrue($user->subscribed());
    }

    public function test_billable_queries_for_missing_subscription(): void
    {
        $user = $this->createUser();

        $this->assertFalse($user->subscribed());
        $this->assertFalse($user->onTrial());
        $this->assertNull($user->subscription());
    }
}
