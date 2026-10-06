# TransactionVerification

Reads a payout receipt screenshot (InstaPay, bank, card or mobile wallet; image or PDF) and checks it against what
the caller expected: the amount, where the money went, and whether the same receipt was already used for another
payout. It **warns, it never blocks**: a verdict is advice for a person, and anything unsure goes to review.

Two OCR engines read every receipt (Tesseract in the app's image, RapidOCR in a small container), and a deciding
field counts only when both read it the same.

A Laravel 12 package (PHP 8.3+). The namespace is `Modules\TransactionVerification`.

## Install

**1. Require it.** The repo is public, so no token is needed:

```json
"repositories": [{"type": "vcs", "url": "https://github.com/Ergasti/transaction-verification"}],
"require": {"ergasti/transaction-verification": "^0.1"}
```

```bash
composer update ergasti/transaction-verification
```

A busy CI can hit GitHub's limit for anonymous requests; a token with no scopes in `COMPOSER_AUTH` lifts it.

Laravel finds the service provider by itself.

**2. Create the tables:** `php artisan migrate` (two tables, on the connection below).

**3. Settings** (`.env`):

| Variable | Default | Set it to |
|---|---|---|
| `TRANSACTION_VERIFICATION_DISK` | `local` | A **private** disk for the screenshots. Production: an S3-style disk with timeouts (below) |
| `TRANSACTION_VERIFICATION_DB_CONNECTION` | the app's default | Another connection, if the tables live elsewhere |
| `TRANSACTION_VERIFICATION_HMAC_KEY` | `APP_KEY` | A long random secret. It keys the blind index of receipt numbers and destinations: **never change it** once rows exist, or duplicates stop being found |
| `TRANSACTION_VERIFICATION_RAPIDOCR_URL` | `http://ocr_rapid:8080` | Where the RapidOCR container answers |
| `TRANSACTION_VERIFICATION_QUEUE` | `default` | A queue of its own in production (e.g. `ocr`) |
| `TRANSACTION_VERIFICATION_ENABLED` | `false` | Only read by callers that check it (a switch for your own submit code) |

A disk with timeouts, so a stuck upload gives up instead of holding a worker (`config/filesystems.php`):

```php
'receipts' => [
    'driver' => 's3',
    'key' => env('RECEIPTS_S3_KEY'),
    'secret' => env('RECEIPTS_S3_SECRET'),
    'region' => env('RECEIPTS_S3_REGION'),
    'bucket' => env('RECEIPTS_S3_BUCKET'),
    'endpoint' => env('RECEIPTS_S3_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'throw' => true,
    'report' => false,
    'http' => ['connect_timeout' => 5, 'timeout' => 20],
    'retries' => 1,
],
```

**4. Pin values in code** (recommended, so a missing `.env` line can't silently change them):

```bash
php artisan vendor:publish --tag=transaction-verification-config
```

**5. OCR.**
- Tesseract and Poppler in the app's image: `apt-get install tesseract-ocr tesseract-ocr-ara tesseract-ocr-eng poppler-utils`.
- RapidOCR as a compose service, from the image the package publishes with each release:

```yaml
ocr_rapid:
  image: ghcr.io/ergasti/transaction-verification-ocr:0.1.1   # the same version as the package
  restart: always
  mem_limit: 2g
  cpus: 4
  environment:
    OCR_THREADS: 4   # keep <= cpus
```

No port needs publishing; the app reaches it on the compose network. Down or slow, receipts go to a person, never
approved.

- **Keep the image version equal to the package version** (`composer show ergasti/transaction-verification`).
- **The image is private:** each server logs in once (see [Pulling the OCR image](#pulling-the-ocr-image) below).
- **Fallback:** `build: ./vendor/ergasti/transaction-verification/ocr-sidecar` instead of `image:` (needs internet for
  the build, ~1 GB).

#### Pulling the OCR image

The image is private, so GitHub hands it only to a logged-in server. Do this **once per server**:

1. **Make a token** on GitHub: Settings → Developer settings → Personal access tokens → **Tokens (classic)** →
   Generate new token. Tick **only `read:packages`** (it can download packages and nothing else). Prefer an
   account meant for servers (a bot account in the Ergasti org) over a person's, so it survives people leaving.
   Give it an expiry you will notice, and note where it is used.
2. **Log in on the server**, as the user that runs `docker compose`:
   ```bash
   echo <token> | docker login ghcr.io -u <github-username> --password-stdin
   ```
   It prints `Login Succeeded`. Piping the token keeps it out of the shell history; Docker stores the login, so
   later pulls just work.
3. **Pull and start it:** `docker compose pull ocr_rapid && docker compose up -d ocr_rapid`, then check
   `docker compose ps ocr_rapid` shows **healthy**.

- **Skipped or expired login:** the pull fails with `denied` / `unauthorized` and the container doesn't start (an
  already running one keeps running). Payouts are unaffected: without the second engine, receipts go to a person,
  never to a wrong match. Log in and pull again.
- **Rotating the token:** make a new one, run the same `docker login` with it, then revoke the old one. Nothing else
  changes.

**6. Keep running:** the scheduler (it recovers rows whose queue job was lost, every 5 minutes) and a queue worker on
`TRANSACTION_VERIFICATION_QUEUE`.

Without Tesseract (a laptop), set `TRANSACTION_VERIFICATION_ENGINE=null`: receipts are still stored, and without the
RapidOCR container they come back `unreadable`.

## Verdicts

The first rule that applies wins:

| Verdict | Meaning |
|---|---|
| `duplicate` | The same receipt (file or reference number) was already submitted for another subject |
| `unreadable` | The amount or the destination could not be read at all, even if everything else was |
| `mismatch` | The amount or the destination was read surely and is different from what was expected |
| `needs_review` | Both match, but the destination can't be confirmed (e.g. a handle was expected and only a phone is shown, or a bank account's digits differ) or a deciding field is unsure, including one that differs but the two engines read differently; a person decides |
| `match` | Amount and destination read with confidence, by both engines, and equal to what was expected |

None of them blocks anything: `unreadable`, `mismatch` and `needs_review` all mean "a person should look".

`status` is `pending` → `processing` → `completed` (or `failed`). A verdict exists only once `completed`.
The latest result is the current one: a result can change after a re-run, or when a copy of the receipt turns up.

## From PHP

Resolve `Modules\TransactionVerification\Contracts\TransactionVerifier`. When the module is disabled there is no
binding, so check `app()->bound(...)` and skip quietly.

| Method | Does |
|---|---|
| `submit(VerificationRequest)` | Stores the file, queues the reading. Idempotent on `idempotencyKey`. |
| `find(uuid)` / `latestFor(subjectType, subjectId)` | The result, or null |
| `reprocess(uuid)` | Reads a finished receipt again with the current engines; null for an unknown uuid |
| `temporaryFileUrl(uuid, minutes = 5)` | A link to the receipt that expires after 1–60 minutes; null for an unknown uuid |

A result's `extracted` holds what was read, masked: phone `********001`, account `****0010`, handle `so***@instapay`.

`TransactionVerificationCompleted` (uuid, subject, verdict, checks; never a phone) fires each time a reading stores
a verdict, and again for another subject's receipt whose result changes because its original was read or re-read
(it became, or stopped being, a `duplicate`). A copy whose result didn't change isn't announced again. Treat the
latest event as current.

## Example caller: payouts

The first app to use this checks affiliate payouts: when one payout is marked paid with a new receipt, it submits
`subject_type=affiliate_payout`, the payout's uuid, the amount in piastres and the payment method's phone, handle or
account number. It submits **after** answering, behind its own on/off switch, and any failure only logs a warning, so
checking can never slow down or change marking a payout paid.

## Over HTTP (another service)

**Only for another app.** Code in the app that installed the package uses the PHP contract above and needs no
keys. The API **ships switched off** (every request gets `503`) until a caller is set up, see
[Switching it on](#switching-it-on).

Base path `/api/internal/transaction-verification/v1`. The full contract, with every field and error, is
`openapi/transaction-verification-internal.yaml` in this repo.

| Method | Path | Does |
|---|---|---|
| POST | `/verifications` | Submit a receipt → `202` |
| GET | `/verifications?subject_type=&subject_id=` | A subject's verifications, newest first (`page`, `per_page` ≤ 100) |
| GET | `/verifications/{uuid}` | One verification |
| POST | `/verifications/{uuid}/reprocess` | Read it again → `202` |
| GET | `/verifications/{uuid}/file` | `302` to a link to the receipt that expires in 5 minutes |

Every answer is `{"success": true, "data": …}` or `{"success": false, "error": "<code>", "message": "…"}`,
including a wrong path (`not_found`) or method (`method_not_allowed`); the one exception is the `302` of `/file`.
Each caller key may make 30 writes and 600 reads a minute. A failed reading's `error` is a fixed message;
the detail stays on the server.

### Signing

Every request carries four headers:

| Header | Value |
|---|---|
| `X-Service-Key-Id` | The caller's key id, e.g. `payouts-app` (not secret) |
| `X-Service-Timestamp` | Unix seconds; refused when more than 5 minutes off |
| `X-Service-Nonce` | 32 lowercase hex characters, new for every request (a reused one is refused) |
| `X-Service-Signature` | `hex(HMAC-SHA256(canonical, secret))`, lowercase |

```
canonical = METHOD \n PATH?SORTED_QUERY \n sha256hex(raw body) \n TIMESTAMP \n NONCE
```

- `PATH` has no host. `SORTED_QUERY` is the query pairs sorted by key, exactly as sent (no re-encoding); with no
  query, leave out the `?` too.
- The body hash is of the exact bytes sent, so the JSON is signed as it goes over the wire. A GET hashes the empty
  string (`e3b0c442…b855`).
- A query that names a key twice (counting encoded spellings such as `%73ubject_id`), or has a key PHP can't read
  (nested over 64 deep, only spaces or NUL), is refused: the sorted string can't tell the order apart.
- It is the same scheme as the affiliate internal API, so one signer serves both.

**Check a signer against this example before calling** (a test pins it; the secret is a test value, never a real
one):

```
secret    : test_secret_do_not_use
request   : GET /api/internal/transaction-verification/v1/verifications?subject_id=123&subject_type=affiliate_payout
timestamp : 1700000000
nonce     : 0123456789abcdef0123456789abcdef
canonical : GET
            /api/internal/transaction-verification/v1/verifications?subject_id=123&subject_type=affiliate_payout
            e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
            1700000000
            0123456789abcdef0123456789abcdef
signature : 8fa7229404a13c5a0705875ab386b3f113cc7a4c85c077227409713300cca20e
```

A caller in Laravel:

```php
$path = '/api/internal/transaction-verification/v1/verifications';
$body = json_encode($payload);                       // see Submitting
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$canonical = implode("\n", ['POST', $path, hash('sha256', $body), $timestamp, $nonce]);

$response = Http::withHeaders([
    'X-Service-Key-Id' => $keyId,                    // payouts-app
    'X-Service-Timestamp' => $timestamp,
    'X-Service-Nonce' => $nonce,
    'X-Service-Signature' => hash_hmac('sha256', $canonical, $secret),
])->withBody($body, 'application/json')->post($baseUrl.$path);
```

### Submitting

The receipt goes **inside the JSON, base64-encoded**, so the signature covers the file and the expected values
together (a multipart upload would leave them unsigned, so any body that isn't JSON gets `415 json_required`).
JPEG, PNG, WebP or PDF, up to 10 MB before encoding.

```json
{
  "subject_type": "affiliate_payout",
  "subject_id": "123",
  "expected_amount_minor": 307000,
  "currency": "EGP",
  "expected_destination": {"type": "phone", "value": "01000000000"},
  "idempotency_key": "affiliate_payout:123",
  "context": {"uploaded_by": "finance"},
  "file_base64": "iVBORw0KGgo…"
}
```

- `expected_amount_minor` is in piastres (3,070 EGP → `307000`).
- `expected_destination.type` is `phone`, `instapay_handle` or `bank` (value: the account number; only its digits
  are compared).
- Sending the same `idempotency_key` again returns the existing verification; retrying is always safe.
- The reading takes a few seconds: poll `GET /verifications/{uuid}` until `status` is `completed` or `failed`.

## Switching it on

Do this only when the other app is ready to call. Both values below are made up by you; nothing generates them.
The example calls the caller `payouts-app`.

**1. Make a secret** (once):

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

**2. The app that installed the package:**

| Variable | Set to | Where |
|---|---|---|
| `TRANSACTION_VERIFICATION_API_KEY_ID` | `payouts-app`: lowercase letters, digits, `-`, `_`, up to 32 | env |
| `TRANSACTION_VERIFICATION_API_SECRET` | the secret from step 1 | your secret store only, never git |
| `TRANSACTION_VERIFICATION_API_SECRET_PREV` | empty; the old secret only while rotating | secret store |
| `TRANSACTION_VERIFICATION_API_IPS` | optional: the caller's IPs, comma-separated. Only reliable when `TRUSTED_PROXIES` is scoped; the signature is the real check | env |
| `TRANSACTION_VERIFICATION_API_ENABLED` | `true` | env |

Then reload the config (`php artisan config:cache`, or restart the container).

**3. The other app** gets the same key id and secret (stored as a secret there too) and the base URL, and signs as
in [Signing](#signing).

- An empty secret can never sign a request, so leaving the placeholders empty keeps the API closed.
- **To rotate:** put the new secret in `_SECRET`, the old one in `_SECRET_PREV`; both work until the other app has
  switched, then empty `_SECRET_PREV`.
- **To switch it off** (e.g. a leaked secret): `TRANSACTION_VERIFICATION_API_ENABLED=false` and reload the config.
  Every request then gets `503`; no code deploy is needed.
- One caller is configured today. A second app needs a small config change (another entry under
  `api.consumers`).

## Operations

- Re-read receipts after an engine upgrade: `php artisan transaction-verification:reprocess {uuid} {uuid}…`
- **Shadow mode** (before anyone relies on a verdict): switch your caller on, let about two weeks of receipts
  through, then run
  `php artisan transaction-verification:shadow-report --since=YYYY-MM-DD > shadow.csv`. Each line has the verdict,
  each check's outcome (`pass` / `fail` / `missing` / `not_applicable`), a receipt link (60 minutes by default,
  `--minutes=1..60`) and an empty `label` column. Open each receipt, compare it with what was paid (by `subject_id`),
  and note in `label` which outcomes were wrong (e.g. `amount wrong`), never the values themselves. A field counts
  right when its outcome was right. The gate is 98% on at least 50 receipts. The CSV holds no phones, accounts or
  amounts, but its links open the receipts: treat it as private and delete it after labelling. `--since` takes
  `YYYY-MM-DD` only.
- **The spec** in `openapi/` is generated by Scramble (`dedoc/scramble`) from the controller's attributes in a host
  app. Re-export it after changing the API; `OpenApiRouteCoverageTest` fails when a route is missing from it.
- **Speed:** each stored scan logs `transaction-verification.timing`: milliseconds per step, and `passes` (2 when
  Tesseract stopped early because both engines agreed, 4 when it read them all). Numbers only, no receipt data.
- **Own queue (production):** set `TRANSACTION_VERIFICATION_QUEUE=ocr` and run a worker for it
  (`php artisan queue:work --queue=ocr`). Deploy the worker first, then set it.
- **Other receipts:** `transaction-verification.parser` takes any `ReceiptParser` class.

## Developing

```bash
composer install
vendor/bin/phpunit
```

The tests run on sqlite in memory with no app around them (Orchestra Testbench). The real-receipt tests skip unless
Tesseract and the private receipt images are present; the images are never committed.
