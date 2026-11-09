# Changelog

## 2.0.0

- **Breaking, security:** `Subscription::active()` (and so `subscribed()`) now only
  counts a paid `active` subscription or a `trialing` one whose trial has not
  ended. `past_due`, `paused`, expired trials and unpaid checkouts no longer grant
  access. A new checkout is stored as `incomplete` until a cycle is paid; the new
  `subscription.payment_link.paid` webhook handler activates it.
- Security: webhook payloads are read from the signed raw body only (query
  parameters and non-JSON bodies are ignored).
- Security: a webhook-created subscription must belong to a model that uses the
  `Billable` trait; `subscriptions()` is ordered newest first.
- Upgrading from 1.x: `subscribed()` is now false while a payment is late. To keep
  access during that time, also check `$subscription->pastDue()` in your gate.

## 1.3.0

- Add `Cashier::completePayment($paymentIntentId)` for the one-off checkout return
  route. Completing is required: until it is called a paid Payment Intent stays
  `processing`, the payment is not final, never withdrawn and no
  `payment_intent.completed` webhook is sent.

## 1.2.0

- Security: `VerifyWebhookSignature` now requires the timestamped
  `x-webhook-timestamp` + `x-webhook-signature-v2` headers and rejects deliveries
  older than 5 minutes (replay protection). The legacy body-only
  `x-webhook-signature` is no longer accepted. OrcaRail sends both headers.
- Dependencies updated (`composer audit` clean).

## 1.1.0

- `Cashier::livemode()`: `false` for sandbox (`ak_test_`) keys.
- Docs: live vs sandbox keys per environment.

## 1.0.1

- Depend on `orcarail/orcarail-php` from public Packagist instead of a local path repository.
- Add GitHub Actions CI (PHPUnit, PHPStan, php-cs-fixer) on PHP 8.2–8.4.

## 1.0.0

- Initial release: Billable trait, subscriptions table + webhook sync, hosted checkout redirects, Cashier-style API wrapping `orcarail/orcarail-php`.
