# Changelog

## 1.1.0

- `Cashier::livemode()`: `false` for sandbox (`ak_test_`) keys.
- Docs: live vs sandbox keys per environment.

## 1.0.1

- Depend on `orcarail/orcarail-php` from public Packagist instead of a local path repository.
- Add GitHub Actions CI (PHPUnit, PHPStan, php-cs-fixer) on PHP 8.2–8.4.

## 1.0.0

- Initial release: Billable trait, subscriptions table + webhook sync, hosted checkout redirects, Cashier-style API wrapping `orcarail/orcarail-php`.
