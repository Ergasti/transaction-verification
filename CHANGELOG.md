# Changelog

## 0.5.1

- The RapidOCR sidecar reads a big tall image (taller than its 1600 px cap and than 1.5 widths: receipts stacked, a
  long screenshot) in page-shaped slices, each cut at the emptiest row near a page's length, and joins their lines in
  order. Before, the whole image was shrunk to fit 1600 px, so two stacked AlexBank confirmations (2479 x 7016) were
  read 565 px wide and their wallet number came out wrong, sending the receipt to review. Now each slice is read like a
  single page (measured: 2 slices, 3.7 s, wallet right; reading it whole at full size took 10.8 s and 4 GB). Images up
  to a page (A4 is 1.41 widths) and phone screenshots within the cap are read whole, exactly as before. The PHP side
  is unchanged; deploy the `0.5.1` sidecar image.
- On `second_engine` `always`, an AlexBank confirmation can stop after the first Tesseract passes when they and
  RapidOCR agree on the amount and the wallet. Waiting for a reference, which that layout never prints, made every
  AlexBank receipt read all four passes (about twice as long). InstaPay still needs its reference to stop early.

## 0.5.0

- `VerificationRequest` takes optional `alternativeDestinations`: other places the money may rightly have gone. The
  receipt passes on any of them (e.g. a bank account paid by InstaPay to the payee's phone). Rows stored by 0.4 keep
  working. The blind index still covers only `expectedDestination`.
  - With alternatives, the best outcome decides, so some verdicts move: a phone that differs from an alternative phone
    is a `mismatch`, and a missing account number next to an unread phone is `needs_review` (it was `unreadable`).
  - In `second_engine` `fallback` mode, a phone read surely that differs counts as sure, so RapidOCR is not asked.
    The default `always` mode reads every receipt with both engines and is unaffected.
- The amount also passes with the piastres dropped (17,906.59 expected, 17,906 read), since banks send whole pounds;
  rounding up still fails. Such receipts move from `mismatch` (or `needs_review` when the amount was read unsurely) to
  `match`. The rule assumes a currency with 2 decimals, like EGP.
- AlexBank "Pay someone" confirmations are read (`AlexBankReceiptParser`), including several stacked in one image
  for a split payout: the amounts are added up, and each must name the same wallet.
- The default `parser` is now `ReceiptRouter`. It hands the text to the first of `parsers` (InstaPay, then AlexBank)
  that recognises it. Text none recognises reads nothing and comes back `unreadable`. An app that set its own `parser`
  is unaffected. A `parsers` entry that doesn't implement `RecognisesReceipts` is a configuration error (an
  exception), not skipped. InstaPay verdicts change only where the text no longer says InstaPay: a screenshot where
  every pass lost both the amount label and the `@instapay` address reads nothing but its reference (`unrecognised`,
  also when it names the sender's bank: a parser that reads nothing off the text doesn't claim it), so it comes back
  `unreadable` and a person looks; a later clean copy is still a `duplicate`. An AlexBank confirmation that lost all its labels keeps the sender's 12-digit account as that
  reference, so two such receipts from one account show as duplicates (a person looks at both). The second engine's text is read
  as the format the first engine recognised, even when it lost those words, unless the first engine read nothing off
  that format; then the second engine's own reading and format stand. Text OCR read nothing from is `unrecognised`
  too. An InstaPay screenshot whose note holds AlexBank's upper-case labels ("WALLET NUMBER", or a line starting
  "PAY SOMEONE" or "BENEFICIARY NAME") is not taken for InstaPay either, so it comes back `unreadable` (recorded as
  `alexbank` when it also names the sender's bank).
- Each reading records its `format` (`instapay`, `alexbank`, `unrecognised`); results show it, and the shadow report
  has a `format` column.
- `shadow-report --subject-type=` lists another caller's checks (default `affiliate_payout`).
- Images up to 30 M pixels are read (was 8 M): AlexBank exports A4 at 300 dpi, 8.7 M pixels a page.

## 0.4.1

- Laravel 11 is supported next to 12 (`laravel/framework: ^11.0|^12.0`); the test suite runs on both.

## 0.4.0

**Breaking** for an app that ran the install command in scripts.

- Removed `php artisan transaction-verification:install` and the `transaction-verification-config` publish tag: every
  setting is an env variable, so an app needs no config file. Set `TRANSACTION_VERIFICATION_DB_CONNECTION` before
  the first `migrate`. A config file an app already has keeps working.

## 0.3.0

**Breaking.** `ExpectedDestination`'s type is the new `DestinationTypeEnum` (`PHONE`, `INSTAPAY_HANDLE`, `BANK`);
the `ExpectedDestination::PHONE` / `INSTAPAY_HANDLE` / `BANK` constants are gone.

- Upgrading: `new ExpectedDestination(ExpectedDestination::PHONE, $phone)` becomes
  `new ExpectedDestination(DestinationTypeEnum::PHONE, $phone)`; from a string, `DestinationTypeEnum::from($type)`
  (an unknown string throws `ValueError`, not `InvalidArgumentException`; `tryFrom()` returns null instead).
- Stored rows, the HTTP API (`expected_destination.type` stays `phone|instapay_handle|bank`) and duplicate
  fingerprints are unchanged.

## 0.2.0

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
- New `php artisan transaction-verification:install`: picks the database connection from the app's list (default
  selected) and writes a one-line `config/transaction-verification.php`.

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
