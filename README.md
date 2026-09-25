# Cashier OrcaRail

Laravel Cashier-style billing for [OrcaRail](https://orcarail.com) — subscriptions, one-off checkout, and webhook sync for stablecoin payments.

Requires the official PHP SDK [`orcarail/orcarail-php`](https://github.com/OrcaRail/sdks-php).

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require orcarail/cashier-orcarail
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag=orcarail-cashier-config
php artisan migrate
```

## Configuration

Add credentials to your `.env`:

```env
ORCARAIL_API_KEY=ak_live_xxx
ORCARAIL_API_SECRET=sk_live_xxx
ORCARAIL_WEBHOOK_SECRET=whsec_xxx
ORCARAIL_BASE_URL=https://api.orcarail.com/api/v1
ORCARAIL_PAY_URL=https://pay.orcarail.com
ORCARAIL_CURRENCY=usd
```

### Live vs sandbox keys

Use a **sandbox organization** key (`ak_test_…` / `sk_test_…`, testnets only, no real funds) in local and staging `.env` files, and the live key (`ak_live_…`) only in production. Create the sandbox with **Go to sandbox** in the OrcaRail dashboard. Webhook payloads carry `livemode` (`false` for sandbox events), so a production app can ignore test events. See [Sandbox](https://docs.orcarail.com/docs/sandbox/overview/).

## Billable model

Add the `Billable` trait to your user (or other billable) model:

```php
use OrcaRail\Cashier\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Subscriptions

Create a subscription and redirect the payer to the hosted pay page:

```php
use Illuminate\Http\Request;

Route::post('/subscribe', function (Request $request) {
    $checkout = $request->user()
        ->newSubscription('default', 'price_xxx')
        ->trialDays(14)
        ->create([
            'payer_email' => $request->user()->email,
            'return_url' => route('billing.return'),
            'cancel_url' => route('billing.cancel'),
        ]);

    return $checkout; // RedirectResponse to hosted pay URL
});
```

Check subscription state locally (synced via webhooks):

```php
$user->subscribed();           // active / trialing / grace period
$user->onTrial();
$user->subscription()?->cancel();
$user->subscription()?->resume();
```

Direct amount (no catalog price):

```php
$user->newSubscription('default')
    ->withAmount([
        'amount' => '10.00',
        'currency' => 'usd',
        'token_id' => '...',
        'network_id' => '...',
        'interval' => 'month',
    ])
    ->create();
```

## One-off checkout

```php
// Catalog price
return $user->checkout('price_xxx', [
    'return_url' => route('orders.return'),
]);

// Direct amount
return $user->charge([
    'amount' => '25.00',
    'currency' => 'usd',
    'tokenId' => '...',
    'networkId' => '...',
    'return_url' => route('orders.return'),
]);
```

Cashier creates a Payment Intent, confirms it when needed, and redirects to the hosted pay URL from `next_action.redirect_to_url.url` (or `pay_url` / `payment_link.link`).

## Webhooks

Cashier registers `POST /orcarail/webhook` (configurable via `ORCARAIL_WEBHOOK_PATH`) and verifies the `x-webhook-signature` HMAC header.

Point your OrcaRail API key webhook URL at:

```
https://your-app.test/orcarail/webhook
```

Handled subscription events sync the local `subscriptions` table (`status`, `trial_ends_at`, `ends_at`). Unknown event types return `200` so you can listen to:

- `OrcaRail\Cashier\Events\WebhookReceived`
- `OrcaRail\Cashier\Events\WebhookHandled`

## Customization

```php
use OrcaRail\Cashier\Cashier;

Cashier::ignoreMigrations();
Cashier::ignoreRoutes();
Cashier::useSubscriptionModel(App\Models\Subscription::class);
```

Access the underlying PHP SDK client:

```php
Cashier::api()->rates->list();
```

## Testing

Unit / feature (testbench):

```bash
cd sdks-laravel-cashier
composer test
composer analyse
composer cs-check
```

### Docker real-flow E2E

Full harness (mirrors WooCommerce `bin/run-woo-real-flow.sh`): starts/reuses the local real-flow API + pay app, spins up a fresh Laravel container with this package, then runs subscribe + checkout + signed webhook sync.

```bash
cd sdks-laravel-cashier
./bin/run-laravel-real-flow.sh
```

Leave the Laravel stack up after a run:

```bash
ORCARAIL_KEEP_LARAVEL=1 ./bin/run-laravel-real-flow.sh
```

Requires Docker, curl, jq, openssl, and the usual real-flow Redis/MailDev stack under `api/`.

## License

MIT
