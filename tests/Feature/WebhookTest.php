<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Feature;

use Illuminate\Support\Facades\Event;
use OrcaRail\Cashier\Events\WebhookHandled;
use OrcaRail\Cashier\Events\WebhookReceived;
use OrcaRail\Cashier\Subscription;
use OrcaRail\Cashier\Tests\TestCase;

final class WebhookTest extends TestCase
{
    public function test_webhook_syncs_subscription_status(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $user = $this->createUser();

        Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->id,
            'type' => 'default',
            'orcarail_id' => 'sub_wh_1',
            'orcarail_status' => 'active',
            'orcarail_price_id' => 'price_abc',
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);

        $payload = [
            'type' => 'subscription.canceled',
            'data' => [
                'object' => [
                    'id' => 'sub_wh_1',
                    'status' => 'canceled',
                    'canceled_at' => '2024-06-01T00:00:00Z',
                    'ended_at' => '2024-06-01T00:00:00Z',
                    'trial_end' => null,
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/orcarail/webhook',
            [],
            [],
            [],
            $this->signedHeaders($body),
            $body,
        );

        $response->assertOk();
        $response->assertSee('Webhook Handled');

        $this->assertDatabaseHas('subscriptions', [
            'orcarail_id' => 'sub_wh_1',
            'orcarail_status' => 'canceled',
        ]);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(WebhookHandled::class);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = ['type' => 'subscription.updated', 'data' => ['id' => 'sub_x']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/orcarail/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(),
                'HTTP_X_WEBHOOK_SIGNATURE_V2' => str_repeat('a', 64),
            ],
            $body,
        );

        $response->assertForbidden();
    }

    public function test_unknown_webhook_type_returns_ok(): void
    {
        $payload = ['type' => 'payment_intent.completed', 'data' => ['id' => 'pi_1']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/orcarail/webhook',
            [],
            [],
            [],
            $this->signedHeaders($body),
            $body,
        );

        $response->assertOk();
        $response->assertSee('Webhook Received');
    }

    public function test_webhook_creates_local_row_from_metadata(): void
    {
        $user = $this->createUser();

        $payload = [
            'type' => 'subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_new_meta',
                    'status' => 'trialing',
                    'price_id' => 'price_xyz',
                    'trial_end' => now()->addDays(7)->toIso8601String(),
                    'metadata' => [
                        'billable_type' => $user->getMorphClass(),
                        'billable_id' => (string) $user->id,
                        'subscription_type' => 'default',
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/orcarail/webhook',
            [],
            [],
            [],
            $this->signedHeaders($body),
            $body,
        )->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'orcarail_id' => 'sub_new_meta',
            'billable_id' => $user->id,
            'orcarail_status' => 'trialing',
        ]);
    }

    public function test_webhook_rejects_legacy_signature_without_timestamp(): void
    {
        $body = json_encode(['type' => 'subscription.updated', 'data' => []], JSON_THROW_ON_ERROR);

        $this->call('POST', '/orcarail/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], $body)->assertForbidden();
    }

    public function test_webhook_rejects_replayed_old_delivery(): void
    {
        $body = json_encode(['type' => 'subscription.updated', 'data' => []], JSON_THROW_ON_ERROR);

        $this->call('POST', '/orcarail/webhook', [], [], [], $this->signedHeaders($body, time() - 301), $body)
            ->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $body, ?int $timestamp = null): array
    {
        $t = (string) ($timestamp ?? time());

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $t,
            'HTTP_X_WEBHOOK_SIGNATURE_V2' => hash_hmac('sha256', $t . '.' . $body, 'whsec_test'),
        ];
    }
}
