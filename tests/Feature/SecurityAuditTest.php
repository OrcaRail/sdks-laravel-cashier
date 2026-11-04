<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Feature;

use OrcaRail\Cashier\Subscription;
use OrcaRail\Cashier\Tests\TestCase;
use OrcaRail\Cashier\Tests\User;

/**
 * plan/security 2026-11-04: access checks and webhook parsing in the Cashier package.
 */
final class SecurityAuditTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function subscriptionFor(User $user, array $overrides = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->id,
            'type' => 'default',
            'orcarail_id' => 'sub_' . uniqid(),
            'orcarail_status' => 'active',
            'orcarail_price_id' => 'price_abc',
            'trial_ends_at' => null,
            'ends_at' => null,
        ], $overrides));
    }

    // CASH-1: active() must be an allowlist. OrcaRail has no "incomplete" status, so the old
    // denylist treated past_due, paused and expired trials as active.
    public function test_cash1_past_due_subscription_is_not_active(): void
    {
        $user = $this->createUser();
        $this->subscriptionFor($user, ['orcarail_status' => 'past_due']);

        $this->assertFalse($user->subscribed());
    }

    public function test_cash1_paused_subscription_is_not_active(): void
    {
        $user = $this->createUser();
        $this->subscriptionFor($user, ['orcarail_status' => 'paused']);

        $this->assertFalse($user->subscribed());
    }

    public function test_cash1_expired_trial_is_not_active(): void
    {
        $user = $this->createUser();
        $this->subscriptionFor($user, [
            'orcarail_status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertFalse($user->subscribed());
    }

    public function test_cash1_unpaid_checkout_does_not_grant_access(): void
    {
        $user = $this->createUser();
        // OrcaRail reports a new subscription as "active" before its first payment.
        $this->fakeHttp->respondWith([
            'id' => 'sub_unpaid',
            'status' => 'active',
            'price_id' => 'price_abc',
            'trial_end' => null,
            'completed_cycles' => 0,
            'latest_payment_link' => [
                'id' => 'pl_1',
                'unique_slug' => 'pl_abc',
                'link' => 'https://pay.orcarail.com/pl_abc',
                'status' => 'active',
            ],
        ]);

        $user->newSubscription('default', 'price_abc')->create();

        $this->assertFalse($user->subscribed());
    }
}
