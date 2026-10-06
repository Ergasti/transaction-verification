# Changelog

## 0.2.0 (unreleased)

**Breaking.** The receipt is read straight from the upload and never stored.

- `submit()` checks the receipt in the calling request and returns the finished result (`completed` or `failed`);
  call it after the response (e.g. `app()->terminating`). No queue job or worker.
- Removed: `reprocess()` and `temporaryFileUrl()` from `TransactionVerifier`, the `transaction-verification:reprocess`
  command, the API's `/reprocess` and `/file` routes, the shadow report's `receipt_link` column and `--minutes`.
- `POST /verifications` answers `200` with the result (was `202`, pending).
- The OCR text is no longer kept. A migration drops `file_disk`, `file_path`, `ocr_text` and `second_ocr_text`.
- Config: `disk`, `folder` and `queue` are gone; the RapidOCR timeout is 20 s (was 60), as the check now holds a web
  request.
- The scheduled task marks a check that died mid-way `failed` (it used to re-queue it), then re-checks copies of its
  receipt that finished before it.
- Events are notifications: read the current result with `find(uuid)` before acting on one.
- Upgrading from 0.1: receipts already on the old disk are left there; delete them by hand.

## 0.1.0

First release as a package; the code is the `Modules/TransactionVerification` module of the first app that used it.

- **Generic defaults:** `connection` is the app's default connection (was `portal`), `disk` is `local` (was
  `hetzner_receipts`). Apps that need other values publish the config:
  `php artisan vendor:publish --tag=transaction-verification-config`.
- The parser is picked by config (`transaction-verification.parser`), and models and migrations follow
  `transaction-verification.connection`.
- The internal API renders its own `429 rate_limited` reply instead of relying on the host app's handler.
- Tests run under Orchestra Testbench, with no app around them.
- The RapidOCR sidecar is published as `ghcr.io/ergasti/transaction-verification-ocr:<version>` on each release,
  after its tests and an end-to-end read pass.
