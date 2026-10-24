# Changelog

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
