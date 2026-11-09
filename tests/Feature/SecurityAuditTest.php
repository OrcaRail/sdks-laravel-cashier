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

    /** @return array<string, string> */
    private function signedHeaders(string $body, string $contentType = 'application/json'): array
    {
        $t = (string) time();

        return [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $t,
            'HTTP_X_WEBHOOK_SIGNATURE_V2' => hash_hmac('sha256', $t . '.' . $body, 'whsec_test'),
        ];
    }

    // CASH-2: the signature covers the raw body, but the handler read $request->all(). With a
    // text/plain body, all() is only the query string, so a captured signed delivery can be
    // replayed with attacker-chosen query parameters.
    public function test_cash2_webhook_payload_comes_from_the_signed_body_only(): void
    {
        $user = $this->createUser();
        $this->subscriptionFor($user, ['orcarail_id' => 'sub_victim']);
        $body = json_encode(['type' => 'ping', 'data' => ['object' => ['id' => 'x']]], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/orcarail/webhook?' . http_build_query([
                'type' => 'subscription.canceled',
                'data' => ['object' => [
                    'id' => 'sub_victim',
                    'status' => 'canceled',
                    'canceled_at' => '2026-01-01T00:00:00Z',
                    'ended_at' => '2026-01-01T00:00:00Z',
                ]],
            ]),
            [],
            [],
            [],
            $this->signedHeaders($body, 'text/plain'),
            $body,
        );

        $this->assertDatabaseHas('subscriptions', [
            'orcarail_id' => 'sub_victim',
            'orcarail_status' => 'active',
        ]);
    }

    // CASH-4: metadata.billable_type from the payload picked any Eloquent model class.
    public function test_cash4_webhook_cannot_attach_a_subscription_to_another_model_class(): void
    {
        $body = json_encode([
            'type' => 'subscription.created',
            'data' => ['object' => [
                'id' => 'sub_foreign_model',
                'status' => 'active',
                'metadata' => [
                    'billable_type' => 'App\\Models\\Admin',
                    'billable_id' => '1',
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/orcarail/webhook', [], [], [], $this->signedHeaders($body), $body);

        $this->assertDatabaseMissing('subscriptions', ['orcarail_id' => 'sub_foreign_model']);
    }

    public function test_cash4_subscription_returns_the_newest_row_of_a_type(): void
    {
        $user = $this->createUser();
        $this->subscriptionFor($user, [
            'orcarail_id' => 'sub_old',
            'orcarail_status' => 'canceled',
            'ends_at' => now()->subMonth(),
            'created_at' => now()->subYear(),
        ]);
        $this->subscriptionFor($user, ['orcarail_id' => 'sub_new', 'created_at' => now()]);

        $this->assertSame('sub_new', $user->subscription('default')?->orcarail_id);
    }
}
