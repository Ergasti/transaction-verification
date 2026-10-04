# Changelog

## Unreleased

First release as a package; the code is the `Modules/TransactionVerification` module of the first app that used it.

- **Generic defaults:** `connection` is the app's default connection (was `portal`), `disk` is `local` (was
  `hetzner_receipts`). Apps that need other values publish the config:
  `php artisan vendor:publish --tag=transaction-verification-config`.
- The parser is picked by config (`transaction-verification.parser`), and models and migrations follow
  `transaction-verification.connection`.
- The internal API renders its own `429 rate_limited` reply instead of relying on the host app's handler.
- Tests run under Orchestra Testbench, with no app around them.
