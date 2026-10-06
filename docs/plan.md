# TransactionVerification module: OCR confirmation layer for payout receipts

The design document, written while the checker was built inside surgeflo-wharehouse (Ergasti/surgeflo-wharehouse#639) and moved here with the code. Status: **phases 1–4 implemented** (Tesseract, RapidOCR as a second engine that must agree, an internal HTTP API that ships off, the first app's hook-up behind a flag that ships off); shadow mode still to run (§10).

Paths below that start with `Modules/TransactionVerification/` are where it was built; here they are relative to the repo root. Paths like `Modules/Affiliate/…`, `Dockerfile` or `docker-compose.yml` are in surgeflo-wharehouse, the first app to use it (it requires `^0.3` and sets `TRANSACTION_VERIFICATION_DB_CONNECTION=portal` in its `.env.example`).

**Since 0.2.0 the receipt is never stored by the checker:** `submit()` checks it in the request, from the upload, after Affiliate's response. The parts below about the receipt disk (`hetzner_receipts`), the queue job and the `ocr` worker, `reprocess`, the receipt link and the stored OCR text describe 0.1 and are gone; §11 Q7 (how long receipts are kept) no longer applies.

## 1. Goal

When an admin marks a creator payout as paid, they upload a transfer screenshot
(`AffiliatePayoutController::store`, `receipt` field). This module reads that
screenshot with OCR and compares it with the payout and the creator's payout method:

| Check | Rule | Source of truth |
|---|---|---|
| Amount | exact, compared in piastres | `affiliate_payouts.amount` |
| Recipient phone | exact after normalising to `01XXXXXXXXX` | the payout method (`affiliate_payment_methods.phone`, decrypted) |
| Duplicate | the same screenshot (or the same transfer reference) must not back two payouts | this module's own history |

It **warns and never blocks**. The payout is marked paid whatever the result. The
verdict is stored, and an event is published for anyone who wants to act on it.

Decisions already made by the product owner:

| # | Decision |
|---|---|
| Q1 | Scope is the admin's receipt at mark-paid. Creators do not upload anything. |
| Q2 | Warn only, never block. |
| Q3 | Exact amount, phone match, duplicate-screenshot detection. |
| Q4 | Screenshots contain personal data. Local OCR or a local model is preferred over cloud. |
| Q5 | About 3,000 screenshots a month (about 100 a day). |
| Q6 | A fully independent nWidart module with its own layering and migrations. |
| Q7 | Methods and APIs for other modules to call. No UI in this module. |
| Q8 | Keep forever, on the S3 storage that creator profile images use. |
| Q9 | No payout-method schema changes. The attached InstaPay screenshots are the first test fixtures (now 9, see §2). |

## 2. What the sample screenshots tell us

The receipts come from the **InstaPay app** (IPN), not a bank app. A sender address like
`saib…@instapay` is an InstaPay address whose prefix names the linked bank; it says nothing
about the app that drew the screen. The "To" line is always `Instapay` plus a phone number.

The fixture set is 9 receipts in `Modules/TransactionVerification/tests/fixtures/instapay/`,
**git-excluded** until §11 Q4 is decided:

| # | Screen | Amount | Recipient | Tests |
|---|---|---|---|---|
| 01 | Transaction Details | 2,050 EGP | recipient A | whole amount printed without decimals; Arabic masked recipient name |
| 02 | Transaction Details | 1,550 EGP | recipient B | duplicate pair with 04 |
| 03 | Transaction Details | 1,050 EGP | recipient C | Arabic masked recipient name |
| 04 | Transaction Details | 1,550 EGP | recipient B | **same transfer as 02** (same reference), different file bytes → must be `duplicate` |
| 05 | Transaction Successful (shared image) | 20.10 EGP | recipient D | piastres, under 1,000, "From" handle and name order flipped, phone in black |
| 06 | Transaction Successful (raw screenshot) | 20.10 EGP | recipient D | same transfer as 05; **no Reference or Date** (collapsed "More Details"), status-bar noise |
| 07 | تمت العملية بنجاح (Arabic app UI) | 20.10 EGP | recipient D | **same transfer as 05**, Arabic labels → duplicate across UI languages |
| 08 | تمت العملية بنجاح (Arabic app UI) | 2,050 EGP | recipient A | **same transfer as 01**, Arabic labels |
| 09 | تمت العملية بنجاح (Arabic app UI) | 10 EGP | recipient D | whole amount; amount label `المبلغ الإجمالي المحول`; Arabic note |

The Arabic title (07–09) doesn't OCR, so their `status_text` stays null; it is informational only.

"Transaction Successful" only appears right after sending; opened later from history the
same transfer shows "Transaction Details". Every screen has a "Note" row (recorded, never
matched). A different-transfers-same-phone case is a data-level test, not a fixture.

(Full phone numbers are left out of this doc on purpose.)

What matters for the design:

- **Every field we match on is Latin digits or English**: amount, phone, reference
  and date. Arabic shows up only in the masked recipient name, which we don't match
  because it is masked. Arabic OCR is still needed so the text around it reads
  cleanly and for receipts from other apps (Vodafone Cash and bank apps often print
  Arabic-Indic digits such as `٢١٨٫٢٧`). But the core fields are within reach of a
  plain OCR engine.
- These screenshots are rendered by the app, not photographed: flat background, no
  skew, high resolution. That is the easiest possible input for OCR.
- **Label-anchored parsing works.** "Transfer Amount", "Reference", "Date:" and
  "To" are fixed labels, and each value sits at a predictable spot next to its label.
- The sender block (`saib…@instapay`, the merchant's own account) is useful for
  a later "was it paid from a known merchant account" check. It is out of scope for v1.

**Missing reference (fixture 06).** A raw screenshot of the success screen has no reference,
so a reused one can't be caught by reference. v1 records `checks.reference = missing` and
does **not** change the verdict. Tightening it to `needs_review` later is one rule in `Matcher`.

### Resolved: whole-EGP payouts vs. receipts with piastres

**Resolved 2026-09-29: option 1.** The InstaPay app prints a whole amount without decimals
(`2,050 EGP`), so a whole-EGP payout gets a whole-EGP receipt and exact matching holds.
The original question is kept below for context.

`affiliate_payouts.amount` is always a whole number of EGP today:
`PayoutRequestService::request(int $amount, …)` and the migration comments say
"whole EGP". **Every sample transfer has piastres** (218.27, 5,042.06, …). Under
"exact amount", every one of them would be flagged as a mismatch against a whole-EGP
payout. Before implementation, confirm one of these:

1. The samples are not real payouts and real payouts transfer whole amounts, so exact matching is right. **(default)**
2. The bank adds or removes fees, so we need a tolerance or a separate fee field.
3. Payouts will move to piastres later. The module already compares in minor units, so nothing changes here.

## 3. OCR engine: recommendation

Constraints: local first, about 100 images a day, a 4-core CPU box (no GPU), a PHP
8.3 / Laravel 12 app, and the work runs async on the queue.

| Engine | Arabic + English | Runs | Speed on CPU | Verdict |
|---|---|---|---|---|
| **Tesseract 5** (`tesseract-ocr` + `ara` + `eng` traineddata, LSTM) through `thiagoalessio/tesseract_ocr` | Good on clean rendered screens; weaker on stylised Arabic | Binary in the app's Docker image, called from PHP | ~0.5–1.5 s per image | **v1 default.** No new service, and the fields that matter are Latin digits on flat backgrounds. |
| **RapidOCR** (PaddleOCR's PP-OCRv5 models on ONNX Runtime: server detector + Arabic recognizer) in a small Python sidecar | Very good, handles mixed right-to-left text | New container (`ocr_rapid`), HTTP from PHP | ~5–7 s per image on 2 cores | **Second engine (phase 2d)**: reads every receipt too, and a match needs both to agree. Full PaddleOCR reads the same but costs twice the memory and image size (see "Second engine" below). |
| Local vision-language model: **Qwen2.5-VL-7B / Qwen3-VL** (or 3B) via Ollama / llama.cpp | Excellent, outputs structured JSON directly | New container, needs lots of RAM, a GPU is realistic | 30–90 s per image on 4 CPU cores | Optional **fallback for unreadable receipts only**, to parse unfamiliar layouts. Not the main path. |
| Cloud (Claude Haiku 4.5, Google Vision, Azure Read) | Excellent | External API | ~2 s | **Off by default** (personal data). The driver interface allows it, gated behind explicit config plus a legal sign-off. |

Every engine sits behind one `OcrEngine` contract, so switching engines is a config
change, and the module can run a second engine on the same image when confidence is
low.

*Update (phase 2d):* the second engine is RapidOCR, and when it runs is `second_engine.mode`. Running it only when
confidence is low, as planned here, is `fallback`. The default is `always`, which reads every receipt, because
confidence alone can't catch an error all four Tesseract passes share. `off` never runs it. See "Second engine" below.

**Measured 2026-09-29 on the 6 fixtures** (Tesseract 5.5, `ara+eng`, `--psm 3`; amount,
phone, reference and date compared character for character):

| Input | Fixtures fully right |
|---|---|
| Raw screenshot | 2 / 6: the grey phone and every grey label are lost on the "Details" layout |
| Tesseract's own adaptive thresholding (`thresholding_method=1/2`) | 2 / 6 |
| GD greyscale + max contrast | 2 / 6 |
| **GD: flatten on white, 2× upscale, greyscale, hard threshold at 0.80** | **6 / 6**, ~0.35 s preprocessing + ~0.3 s OCR |

So Tesseract stays the primary engine (a second one was added later for the 90% bar, see "Second engine" below). With the Arabic-UI samples (07–09) added, the phase 2a
parser rules (reference and date found by shape, bidi marks stripped, Arabic labels for amount and recipient) read all
9 fixtures right: in Arabic the values come through in every pass while the grey labels come and go. The threshold is sensitive
(0.85 and 0.90 each lose one field), so the engine reads each receipt four times (2× at 0.75, 0.80 and 0.85,
plus 1.5× at 0.80, the only single pass that read all 9 fixtures right; ~3 s per receipt) and the parser votes
per field. The orange "EGP" never reads correctly
("Ecp", "cece", Arabic letters), so it is never used. The fields that decide the verdict need their
label: the amount sits above "Transfer Amount"/"المحول", and the recipient's phone or handle is read only
from the block under "To"/"إلى", which ends at the details card (a label, or a reference-shaped number or a
date, because Arabic OCR loses the grey labels), a sender label in any case, or the footer. A pass that lost
the "To" label doesn't vote on the recipient, and a "To" line after the note label is never the label ("To" always
comes first), however the note wraps. The amount must be the single number above its label (two labelled amounts that disagree make the pass ambiguous), and four
digits in a row (a dropped thousands comma or decimal point) are refused. The reference and the date are found by
shape. Nothing is read from the labelled note lines (not even the date or a line reading "To Instapay"); note text OCR
moves away from its label is covered by the "To"-block and two-candidate rules, which count it against, and two different candidates for a field are never guessed
between: a pass that saw two counts against the winner. Confidence is agreement between the four passes, not
OCR accuracy (they read one image, so a shared error still scores 1.0); the rules above refuse the shapes such
errors take. All four agree on every field of all 9 fixtures. **Residual risk:** a small amount whose decimal point
is lost ("1.50" read as "150") looks like a genuine amount and can't be refused by shape. The 1.5× pass sees the
glyphs at another size (the size caps shrink every pass by the same factor, so it stays 0.75 of the others), so one pass keeping the dot drops confidence to 0.75, under the 0.90 threshold, and the
receipt goes to review; a dot lost at both sizes, with the expected payout equal to the misread value, must also be
lost the same way by the second engine (below) to pass. Shadow mode (phase 4) measures whether it happens. Letter/digit confusion
(`O` for `0`) showed up only in the sender handle, which is informational.

**Duplicates: why there is no perceptual hash.** Measured 2026-09-29 on the 9 fixtures plus distorted copies
(JPEG at quality 50, 60% resize, 3% crop, status bar cut off). Every InstaPay receipt is the same screen, and
what tells two apart (amount, name, phone, reference) is a few small patches of digits, so a slight crop moves
the hash more than a different receipt does:

| dHash grid | closest two *different* receipts | furthest *same* receipt, distorted |
|---|---|---|
| 8×8 (64 bits, the old plan) | 1 | 10 |
| 16×16 (256 bits) | 6 | 51 |
| 32×32 (1024 bits) | 27 | 189 |

No grid separates them, even with crops excluded (16×16: 6 vs 15), so a "duplicate" verdict from it would
flag almost every real receipt; 05 and 06 (the case it was meant for) are 22 bits apart anyway. Duplicates are
caught by the identical file (sha256) and the transfer reference, the one value unique to each transfer. A
copy with the reference hidden stays uncaught: the phone can't link it, since one creator receives many transfers.

**Going live (phase 2c, measured 2026-09-29).** The Docker base image (`dunglas/frankenphp:php8.3`) is Debian 13
and ships the same Tesseract as the dev box (5.5.0, `ara`/`eng` 4.1.0); the 36 pass images of the 9 fixtures,
OCR'd inside it, read 9/9 exactly with every pass agreeing. The packages add about 125 MB. PDFs: page 1 is rendered
with `pdftoppm -scale-to 2400` (longest side 2400 px). Rendering at the PDF's own size lost 09's phone (saving as
PDF recompresses the image), while 200/300 dpi and 2400 px read all 8 receipts tried; the fixed pixel size also
bounds memory, since a 200-inch page renders 2400×2400 in 0.2 s. Tesseract runs with `OMP_THREAD_LIMIT=1`: the
same speed per pass (0.49 s vs 0.45 s) at less than half the CPU (0.39 vs 0.88 CPU-s), which matters on a box
shared with FrankenPHP. JPEG phone photos are turned upright from their EXIF orientation (3/6/8) before OCR.

**Second engine (phase 2d, measured 2026-09-29).** Payouts are money, so a match must be at least 90% sure. The four
Tesseract passes can't say that on their own: they read one image with one engine, so an error they share still
scores 1.0. A second, unrelated engine that must read the same deciding fields can. Measured on the real receipts:

| | Tesseract (shipped) | PaddleOCR 3.1.1, Arabic | RapidOCR 3.9.2, server detector |
|---|---|---|---|
| Recipient, amount, reference, date | 15/15 exact | 9/9 exact (tried on the first 9) | 15/15 exact (after the parser took "ToInstapay") |
| Wrong values | 0 | 0 | 0 |
| Per receipt | ~3.8 s (4 passes) | ~4.3 s | ~4 s all cores, ~5–7 s on 2 |
| Memory | ~50 MB | 1.8 GB | ~0.4 GB idle, ~1.2 GB peak |
| Image | +125 MB | 2.39 GB | 0.94 GB |

RapidOCR runs the same PaddleOCR models without the PaddlePaddle framework (training, GPU kernels), which is what
made Paddle heavy. It reads the upright greyscale image `ImagePreprocessor::upright()` makes (colour read the same),
with headless OpenCV (read the same, no X11/GL libraries), and drops boxes it scores under 0.5 (a dropped box is a
missing field, so review). The lighter "mobile" detector split "2,050" from "EGP" onto two lines (1/9 through the
parser), so the server detector is used. It runs as the `ocr_rapid` container (`ocr-sidecar/`): one receipt at a time
(a lock; `/health` answers during a read, so the healthcheck never mistakes a busy sidecar for a wedged one), 2 of
the 4 cores (`OCR_THREADS`), capped at 2 GB (~1.4 GB measured on a dense 8 MP page), no published port. The base image is pinned by digest and every Python
package by version (installed `--no-deps`), and the models are baked in at build, so a rebuild is the image measured
and it never fetches anything. It is sent 8-bit greys (a noisy 8 MP photo: ~3 MB, under its 10 MB limit).
Deploy with `docker compose up -d --build ocr_rapid` whenever `ocr-sidecar/` changes (the tag is fixed).
*Update (2026-09-30):* cleared to use all 4 cores. The sidecar now defaults to `OCR_THREADS=4` (`cpus: 4` in
`docker-compose.yml`, ~4 s a receipt), and the four Tesseract passes run side by side, one core each
(`TRANSACTION_VERIFICATION_TESSERACT_PARALLEL=4`, ~1.8 s instead of ~3.4 s). The image build now runs
`ocr-sidecar/test_server.py` (row joining and the handler, with a fake engine), so a broken sidecar never becomes an image.
*Update (2026-10-04):* measured where a scan's ~5.3 s went (15 receipts, sidecar on 4 cores): RapidOCR's detector
~3.2 s, Tesseract's PHP image prep ~1.2 s (4 × `binarise()`), Tesseract itself ~0.6 s, RapidOCR's recognizer ~0.4 s;
upload, decode and row re-reads ~0. Of 11 RapidOCR variants (smaller image, mobile / v6 small / v6 tiny detectors,
OpenVINO, combinations), all read the 15 receipts exactly; the sidecar now runs the **mobile detector on OpenVINO at
most 1600 px a side**: ~0.4 s a receipt (was ~3.5 s), ~0.7 GB peak (was ~1.2 GB), same reading as the mobile detector
on onnxruntime. The mobile detector's old "2,050" / "EGP" split (above) is mended by the row joining. OpenVINO adds
~180 MB to the image; its telemetry package is left out, and the image reads with no network. A scan is now ~2.1 s
with the engines one after the other. Each stored scan logs `transaction-verification.timing` (ms per step, numbers
only), and the sidecar answers with its own `ms` breakdown.
*Update (2026-10-04, later):* Tesseract's image prep now decodes once and enlarges, greys and quantises once per
scale (`ImagePreprocessor::binariseAll()`); each pass only thresholds the palette. The PNGs Tesseract reads are
byte-identical to before on all 15 receipts and a sideways photo, so its readings are unchanged. Prep ~1.2 s →
~0.6 s; Tesseract ~1.7 s → ~1.3 s; a whole scan (both engines, one after the other) ~5.3 s → ~1.8 s.
*Update (2026-10-04, server latency):* on `always`, RapidOCR now reads while Tesseract's passes run
(`TesseractEngine::read($path, $meanwhile)`; `fallback` and `off` stay one after the other): ~1.8 s → ~1.57 s on
a 5-core laptop, nearer ~1.3 s where the cores don't contend. `first_ms` in the timing log now includes the
overlapped RapidOCR read; `second_ms` is RapidOCR's own. Scans can also have their own queue worker
(`supervisord.conf` `laravel-queue-ocr`, `--sleep=1`) instead of the shared `default` one (sleep 3, behind
other jobs). Deploy order: ship the image first (the worker idles on an empty `ocr` queue), then set
`TRANSACTION_VERIFICATION_QUEUE=ocr` in the server `.env` and restart the app container. Never the other way:
jobs would wait on a queue nobody listens to. Rollback: remove the line and restart. Locally leave it unset
(`composer run dev` doesn't listen on `ocr`).
*Update (2026-10-04, verdict and adaptive passes):* a failed amount or destination check is a mismatch only when
that field was read surely (at or above the threshold); an unsure one is needs_review. Before, a failed check
outranked confidence, so a receipt where RapidOCR read an InstaPay handle right and Tesseract read a `0` as `o`
(all four passes alike, so 100%) was labelled "doesn't match"; the engines' disagreement now sends it to a person.
On `always`, Tesseract reads its first `early_passes` (2: the 2× .75 and .80 passes) with RapidOCR alongside, and
the other two only when the amount, the destination or the reference is missing, split between the passes, or
read differently by RapidOCR. On the 15 receipts: 14 stop after two (receipt 06's reference needs all four), no
field read wrong, ~1.07 s instead of ~1.6 s. `TRANSACTION_VERIFICATION_TESSERACT_EARLY_PASSES=0` restores four
always. The timing log's `passes` (2 or 4) shows how often it stops early.

`second_engine.mode` decides how it is used:
- **`always`** (default): it reads every receipt; a deciding field Tesseract read counts only if it reads the same
  (`ExtractedFields::confirmedBy`), else its confidence drops to 0 and the match goes to a person.
- **`fallback`**: it reads only when Tesseract is unsure (the amount or recipient missing or under the threshold);
  it fills a missing field, or confirms an unsure one it reads the same (`filledFrom`). Lighter, but a confidently
  wrong Tesseract read is never checked.
- **`off`**: Tesseract alone. Any other value (a typo) behaves as `always`, so a mistake never switches the check off.

Down, slow (60 s) or failing, it reads nothing: the row still completes, a `second-engine-failed` warning is logged,
and on `always` the match waits for a person. The threshold is 0.90. Confidence is the share of the Tesseract passes
that read a field and agree (a pass that read nothing doesn't count), so no pass that read it may disagree, and on
`always` RapidOCR must read it the same too. On `fallback`, a field Tesseract missed is decided by RapidOCR alone. Both engines agree on every field of all 15
real receipts, so none of them goes to review.

Supporting libraries (PHP, via composer):

| Library | Why |
|---|---|
| ~~`thiagoalessio/tesseract_ocr`~~ | Dropped: Laravel's `Process` runs the `tesseract` binary directly (plain text; confidence comes from agreement across the threshold passes), no wrapper needed |
| GD (already in the Docker image) | Before OCR: flatten, greyscale, upscale, threshold. The threshold maps the 256-entry palette, not every pixel, so it stays fast. Imagick also scores 6/6 but is not in the image |
| GD + `exif` (both in the image) | Phase 2c: turn a phone photo upright from its EXIF orientation before OCR |
| ~~`jenssegers/imagehash`~~ | Dropped: a perceptual hash can't tell two InstaPay receipts apart (see "Duplicates" above) |
| `poppler-utils` (`pdftoppm`) in Docker | The receipt field also accepts PDF, so the first page is rasterised to PNG before OCR |

Dropped in phase 1: `propaganistas/laravel-phone`. The app's existing `normalizePhoneNumber()` /
`isValidEgyptianMobile()` helpers handle `+20` / `0020`. A tiny `Digits::fold()` (Arabic-Indic and
Persian → ASCII) is shared by `AmountParser` and `Matcher`; `Matcher` also rejects a phone with
extra digits, which the core helper would otherwise trim to 11 and let pass.

Docker additions: `tesseract-ocr tesseract-ocr-ara tesseract-ocr-eng poppler-utils`
(the `tessdata_best` models for `ara`, for better Arabic), and the `ocr_rapid` service in `docker-compose.yml`.

## 4. Module shape (fully independent)

```
Modules/TransactionVerification/
  module.json                  # providers: TransactionVerificationServiceProvider
  composer.json                # psr-4 Modules\TransactionVerification\ => app/
  config/transaction-verification.php
  app/
    Contracts/                 # PUBLIC API: the only thing other modules may import
      TransactionVerifier.php
      OcrEngine.php
      ReceiptParser.php
    Data/                      # PUBLIC API: immutable DTOs
      VerificationRequest.php  # subject, expected amount (minor), currency, destination, file
      ExpectedDestination.php  # type (DestinationTypeEnum: phone|instapay_handle|bank), value
      VerificationResult.php   # status, verdict, per-check results, duplicate links, error (no extracted fields)
      CheckResult.php
      ExtractedFields.php      # what the parser read: amount, phone, handle, reference, per-field confidence
    Enums/                     # PUBLIC API
      VerificationStatusEnum.php  # pending, processing, completed, failed
      VerdictEnum.php             # match, mismatch, duplicate, needs_review, unreadable
      CheckOutcomeEnum.php        # pass, fail, missing, not_applicable
      CheckEnum.php               # amount, destination, duplicate, reference (the checks' keys)
      DestinationTypeEnum.php     # phone, instapay_handle, bank
      SecondEngineModeEnum.php    # always, fallback, off (anything else is always)
    Events/                    # PUBLIC API
      TransactionVerificationCompleted.php
    Services/                  # internal
      TransactionVerificationService.php   # implements TransactionVerifier
      ReceiptStorage.php                   # target: private S3 disk, keys, signed URLs (phase 1 stores inline in the service)
      DuplicateDetector.php
      Matcher.php                          # deterministic comparisons → verdict
      AmountParser.php, Digits.php, DateParser.php  # phone normalising reuses the app's normalizePhoneNumber()
      Ocr/{TesseractEngine,NullEngine}.php         # target also: PaddleOcrEngine, OllamaVlmEngine
      Parsers/InstaPayReceiptParser.php            # English and Arabic app UI; target also: GenericReceiptParser
      Preprocess/ImagePreprocessor.php             # GD: flatten, scale per pass, greyscale, palette threshold
    Jobs/ProcessVerificationJob.php        # queued; safe to dispatch twice (the service claims pending→processing atomically)
    Models/TransactionVerification.php
    Http/Controllers/Internal/VerificationController.php
    Providers/{TransactionVerificationServiceProvider,RouteServiceProvider}.php
    Console/ReprocessVerificationsCommand.php  # re-run OCR after an engine upgrade
  database/migrations/
  routes/internal.php
  tests/{Unit,Feature,fixtures/instapay/}
  README.md                  # callers' guide (phase 3)
```

**Independence rules** (enforced, not just intended):

- The module does not import `Modules\Affiliate\*` or `Modules\PortalWidget\*`, and
  does not know what a payout is. The caller gives it a generic `subject_type` /
  `subject_id` (e.g. `affiliate_payout` / `123`) plus the expected values.
- `deptrac.yaml` gets two new layers:
  `TransactionVerificationPublicApi` (Contracts, Data, Enums, Events) and
  `TransactionVerificationModule` (everything else).
  - `TransactionVerificationModule` → `[Core, TransactionVerificationPublicApi]` only.
  - `AffiliateModule` gains `TransactionVerificationPublicApi` (never the internals).
  - Core gains nothing. It has no reason to call the module.
- The module's own `BoundaryRatchetTest` (so `composer boundary` runs it)
  greps for forbidden imports in both directions.
- `modules_statuses.json` gets `"TransactionVerification": true`. When the module is
  **disabled**, Affiliate's container lookup finds no `TransactionVerifier` binding
  and skips verification quietly. The payout flow must never depend on this module.

## 5. Public API

### PHP contract (for modules in the same process)

```php
interface TransactionVerifier
{
    /** Stores the file, creates a pending row, queues OCR. Idempotent on $request->idempotencyKey. */
    public function submit(VerificationRequest $request): VerificationResult;

    /** Newest verification for a subject, or null. */
    public function latestFor(string $subjectType, string|int $subjectId): ?VerificationResult;

    public function find(string $uuid): ?VerificationResult;

    /** Re-run OCR and matching with the current engine (after an engine upgrade or a manual retry). */
    /** Phase 3. */
    public function reprocess(string $uuid): VerificationResult;

    /** Short-lived signed URL to the stored screenshot (private bucket). */
    /** Phase 3. */
    public function temporaryFileUrl(string $uuid, int $minutes = 5): string;
}
```

*Update (phase 3):* both are built and return `null` for an unknown uuid, like `find()`
(`?VerificationResult`, `?string`). `reprocess()` re-opens only a finished (`completed` or `failed`) row; a row still
waiting or being read is returned as it is. `temporaryFileUrl()` accepts 1–60 minutes. `VerificationResult` also
gained `extracted`: what was read, with the phone, handle and account masked.

`VerificationRequest` fields:

- `subjectType`, `subjectId`, `merchantId` (optional, used for scoping)
- `expectedAmountMinor` (int piastres), `currency` (default `EGP`)
- `expectedDestination` (`ExpectedDestination`: type plus the plain value. The
  module encrypts it before storing.)
- `file`: an `UploadedFile` (a disk + path variant is added only if a caller ever needs it)
- Validation: `subjectType`/`subjectId` 1–64 chars, `idempotencyKey` 1–255, `expectedAmountMinor` > 0,
  `currency` 3 capital letters, destination type a `DestinationTypeEnum` (PHP's types reject
  anything else) with a non-empty value; other bad values throw `InvalidArgumentException`
- `idempotencyKey`, `context` (free-form JSON, e.g. who uploaded it)

### Events

`TransactionVerificationCompleted { uuid, subjectType, subjectId, verdict, checks }`
is dispatched after commit. It never carries the plain phone number or the image.
It can fire twice for one uuid: a copy checked before its original had a reference is
re-decided once the original runs, and its second event says `duplicate`. The latest event is current.
Affiliate can listen to it to write an activity-log line or notify the finance
admin. That listener is Affiliate's code, not this module's.

### Internal HTTP API (for services in other processes)

Same auth and throttling style as `/api/internal/affiliate/v1` (`verify.service:*`
HMAC plus a named limiter):

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/internal/transaction-verification/v1/verifications` | multipart submit (the `VerificationRequest` fields); returns `202` plus the uuid |
| GET | `…/verifications/{uuid}` | status, verdict, checks, masked extracted fields |
| GET | `…/verifications?subject_type=&subject_id=` | history for a subject |
| POST | `…/verifications/{uuid}/reprocess` | queue a re-run |
| GET | `…/verifications/{uuid}/file` | `302` to a short-lived signed URL |

An OpenAPI spec is exported to `openapi/` like the other internal APIs.

*Update (phase 3):* built as above (`openapi/transaction-verification-internal.yaml`; callers' guide in the module
README), with three changes:
- **Submit is JSON with the file base64-encoded, not multipart.** The HMAC signs `sha256(raw body)`, and PHP hands a
  multipart body to the app already unpacked (`getContent()` is empty), so the file and the expected amount and
  destination would travel unsigned. Base64 costs a third more bytes and keeps the whole request signed.
- **The module has its own copy of the HMAC check** (same headers and canonical string, so one signer serves both
  APIs), not `verify.service`: that middleware is Affiliate's internals, which §4 forbids, and using it would tie this
  API to Affiliate being enabled. Its config is `transaction-verification.api` (off by default, one consumer,
  rotation secret, optional IP allowlist).
- **An unknown uuid is `404`**, not the affiliate API's anti-enumeration `200`: these uuids are random v4.
Limits: 600 reads and 30 writes a minute per caller key. `php artisan transaction-verification:reprocess {uuid*}` re-runs from the CLI.

## 6. Data model (own migrations, `portal` connection)

The connection is hardcoded to `portal`, next to the Affiliate tables, like every module
table. Make it configurable only if a deployment ever needs another connection.

**`transaction_verifications`**

| Column | Type | Notes |
|---|---|---|
| id, uuid | | |
| subject_type | string(64) | index with subject_id |
| subject_id | string(64) | |
| merchant_id | unsignedBigInteger, null | index |
| status | string(16) | `VerificationStatusEnum` |
| attempts | tinyint, default 0 | claims so far; a run stores its verdict only while it holds the latest claim |
| verdict | string(16), null | `VerdictEnum` |
| expected_amount_minor | bigInteger | |
| currency | char(3) | |
| expected_destination | text, `encrypted:array` | `{type, value}` |
| expected_destination_hash | char(64) | HMAC blind index |
| file_disk, file_path | string | private S3 key |
| file_mime, file_size | | |
| file_sha256 | char(64) | index; exact-duplicate lookup |
| engine, engine_version | string | e.g. `tesseract+rapidocr`, `5.5.0 + rapidocr 3.9.2 / …` (only `tesseract` when the second engine wasn't asked) |
| ocr_text | longText, `encrypted` | raw text, kept for re-parsing and audit |
| second_ocr_text | longText, `encrypted`, null | the second engine's text; null when it wasn't asked, '' when it read nothing or failed |
| extracted | text, `encrypted:array` | `{amount_minor, currency, phone, handle, account, reference, occurred_at, status_text, note}` plus per-field confidence, after the second engine's check |
| reference_hash | char(64), null | HMAC of the normalised reference; index |
| checks | json | `{amount:{outcome,expected,found}, destination:{outcome}, duplicate:{outcome, of:[uuid…], by:[sha256|reference]}, reference:{outcome}}` with no plain personal data; `reference` is informational only |
| confidence | decimal(4,3), null | lowest confidence among the fields that decided the verdict |
| error | string, null | why processing failed (status `failed`); unreadable rows leave it null |
| idempotency_key | string, unique | |
| context | json, null | caller's free-form metadata (not encrypted: callers must not put personal data here) |
| processed_at, timestamps | | |

The data is kept forever, so there is no soft-delete and no pruning job.
**`transaction_verification_duplicates`**: `verification_id`, `duplicate_of_id`,
`method` (`sha256|reference`). This is a history of
every match found, which is useful when a fraud pattern needs investigating.

Personal data handling follows the Affiliate precedent (`AffiliatePaymentMethod`
encrypted casts and `$hidden`): the phone, reference and OCR text are encrypted, and
lookups use a keyed HMAC blind index (`hash_hmac('sha256', normalised, config key)`).

## 7. Pipeline

```
submit() ─ validate, store file on private disk, sha256, insert row (pending) ─▶ queue
ProcessVerificationJob
  1. load file ─ PDF? page 1 → PNG (pdftoppm -scale-to 2400; JPEG? EXIF orientation 3/6/8 applied)
  2. preprocess ─ GD flatten, upscale, greyscale, threshold; once per configured pass (2× at 0.75/0.80/0.85,
                 1.5× at 0.80; size caps shrink all passes alike, so the 1.5× one stays smaller), each pass OCR'd, the parser votes per field; Tesseract runs single-threaded
  3. (removed: pHash, see §3 "Duplicates")
  4. OCR (configured engine) ─ plain text per pass; the parser votes, confidence = passes that agree
  5. parse ─ pick parser by layout fingerprint (InstaPay/IPN first, generic fallback)
             label-anchored: "Transfer Amount" / "Reference" / "Date:" / "To" (+ Arabic labels)
  6. normalise ─ digits, amount → minor units, phone → 01XXXXXXXXX, date → Africa/Cairo
  7. second engine (RapidOCR sidecar) ─ 'always': reads the upright greyscale image, a deciding field counts only
                 if both read it the same; 'fallback': only when a deciding field is missing or under the threshold;
                 'off': skipped. Failure = read nothing (a warning), never a failed row. Runs before the
                 reference is hashed, so a reference only the second engine read still reaches step 9
  8. match (deterministic PHP, no model decides anything)
  9. duplicates ─ sha256 equal │ same reference_hash (earlier row, different subject)
 10. verdict ─ persist ─ dispatch TransactionVerificationCompleted (after commit)
 11. later copies ─ a later row (different subject, same reference_hash) that ran before this
                    row's reference was stored: a finished one is re-decided from its stored
                    reading (no new OCR run), one still processing is sent back through the job.
                    Runs after a stored verdict and after a failure alike
```

The reference hash is stored before the duplicate lookup, so either a later copy's lookup
sees it or this row's step 11 sees that copy. A row that fails after reading keeps its hash
and still catches copies on both sides.

**Recovery:** every claim increments `attempts`, and a run may only store its verdict
while it still holds its claim. A scheduled task (every 5 minutes) re-queues `pending` or
`processing` rows untouched for `stale_minutes` (default 15): a lost queue push, a failed
claim, or a worker that died mid-run. A row claimed 3 times without finishing is marked `failed`.

**Verdict order** (the first rule that applies wins):

1. `duplicate`: any duplicate hit against a **different** subject. The same subject
   re-submitted is idempotent, not a duplicate.
2. `unreadable`: the amount or the destination could not be extracted at all.
3. `mismatch`: the amount or the destination was extracted and differs.
4. `needs_review`: the amount matches but the destination can't be confirmed (`not_applicable`:
   a bank account that isn't the same digits, or a handle expected and only a phone shown), or a deciding field is below the confidence threshold
   (default 0.90), which includes a field the second engine read differently or not at all.
5. `match`

**Destination rules:**

- Expected type `phone` (a wallet, or InstaPay with a phone): both sides must have a real
  mobile's shape (after `+20`/`0020`, exactly `1XXXXXXXXX` or `01XXXXXXXXX`; an extra OCR digit
  fails), the normalised values must be equal, and the prefix must be a valid Egyptian mobile.
  A receipt showing only a handle leaves the phone `missing`, so the verdict is `unreadable`.
- Expected type `instapay_handle` (the method has only an `@instapay` address):
  compare the lowercased handle if the receipt shows one. If the receipt shows only a
  phone, the outcome is `not_applicable` and the verdict stops at `needs_review`.
- Expected type `bank` (value: the saved account number): InstaPay receipts to a bank account or
  card print the full number under a bare "To" / "إلى" (fixtures 10–15, 2026-09-29). The same digits,
  ignoring spaces and dashes, pass. Anything else, or no number read, is `not_applicable`, so it goes to
  a person and is never a `mismatch`: the field is free text, and an IBAN typed there never equals the
  receipt's number even when the money arrived. Mobile-wallet receipts ("To Mobile Wallet") show the
  phone, compared as `phone`.
- Receipts from a bank's own app (not InstaPay) often mask the account (`****1234`) and don't carry
  InstaPay's labels. v1 has no parser for them: a masked number is too short to be read as an account,
  and fields without their InstaPay label read as missing, so such a receipt ends at `unreadable` or
  `needs_review`, never `match`. None has been seen yet; add a parser when shadow mode shows one.

Informational only (recorded, never part of the verdict in v1): the title/status text
("Transaction Successful" vs "Transaction Details"), the transfer date compared with
the payout time, and the note. The sender's name and handle are never extracted; the raw OCR
text (`ocr_text`, encrypted and hidden) holds whatever the receipt shows, the sender included.

## 8. Integration with Affiliate (the only consumer in v1)

This is a small change inside Affiliate, in a later PR after the module exists:

- In `AffiliatePayoutController::store`, after the payout commits successfully (and
  only when a new receipt was stored, not on an idempotent no-op):
  `app()->bound(TransactionVerifier::class) && app(TransactionVerifier::class)->submit(...)`.
  - Expected amount: `(int) round($payout->amount * 100)`.
  - Expected destination: `fullDestination()` of the payout's payment method.
  - Idempotency key: `affiliate_payout:{uuid}:{sha256}`.
  - Wrapped in a try/catch that logs and moves on. Mark-paid never fails because of this module.
- `bulkMarkPaid` shares **one** batch proof across many payouts. That would give
  every payout except the first a `duplicate` verdict and an amount mismatch. **v1
  does not submit bulk proofs.** See §11 Q3.
- Affiliate may optionally listen to `TransactionVerificationCompleted` and write an
  `activity()` log entry on the payout. There is no UI work, as agreed.

*Update (phase 4):* built behind `TRANSACTION_VERIFICATION_ENABLED` (default off) as
`Modules\Affiliate\Services\PayoutReceiptCheck`. It submits **after the response** (`app()->terminating()`): the
receipt store has no timeout, and a hung upload must not delay a committed payout or tempt a retry that pays twice.
(*Update:* the store now has timeouts, see the `disk` note in §10; a slow upload still waits up to about 40 s, so it
stays after the response.)
Three differences from the text above:
1. **Two entry points, not one:** the admin JSON API (`POST /api/affiliate/admin/v1/payouts`, receipt optional there)
   marks single payouts paid too, so it submits as well (when a receipt is sent and a preferred method exists).
2. **Raw values, not `fullDestination()`:** for a bank that returns `"<bank name> <account>"`, and the matcher
   compares only digits, so a digit in the bank's name would break it. A wallet sends its phone, InstaPay its
   address as a handle (or its phone), a bank its account number; a method without that value isn't submitted.
   The expected amount is the stored payout amount, which mark-paid has already floored to whole pounds.
3. **No listener / `activity()` entry yet:** Affiliate has none today and the verdict is on the module's row; add it
   when someone needs the verdict in the payout's history. Shadow mode uses
   `php artisan transaction-verification:shadow-report` instead (README "Operations").

## 9. Tests and accuracy gate

- **Fixtures**: `tests/fixtures/instapay/01.png`–`09.png` (§2) plus `expected.json` holding
  the per-image amount, phone, reference and date. Git-excluded (personal data), so tests that
  need them skip when the files are absent. `tests/fixtures/ocr/instapay-01.txt`–`09.txt` are the
  engine's recorded output with every value made up (phones, references, names, amounts, dates, notes), and are committed. They were
  recorded with the earlier 3-pass engine; the parser reads any number of passes, and the 4-pass
  voting is pinned by its own parser and feature tests.
- **Unit (phase 1, done)**: `AmountParser` (`2,050`, `20.10`, `5,042.06`, `٢١٨٫٢٧ ج.م`;
  rejects `20,10`, `20.1`, `.50`, negatives), the `Matcher` verdict table (every row of the §7 order
  plus pairwise precedence).
- **Feature (phase 1, done)**: `DuplicateDetector` (exact file, same reference on a different
  subject, same subject re-submitted, earlier rows only), and submit → job → verdict: idempotency,
  a copy checked before its original, a failed original, stuck-row recovery, a slow worker's
  refused write. Phone normalising is the app's
  `normalizePhoneNumber()`, already tested in core. Phase 2a added `DateParser`
  (`26 Sep 2026 07:29 PM` in Cairo time).
- **Parser tests** use recorded OCR text output with the real phones, references and names
  replaced by synthetic ones, so CI needs neither the Tesseract binary nor the real images: `InstaPayReceiptParser` on both screens (05 "Successful", 01 "Details"),
  the Arabic-name receipts (01, 03) and the no-reference raw screenshot (06).
- **Engine tests**: `TesseractEngineTest` fakes the binary. `RealReceiptOcrTest` (`#[Group('ocr')]`)
  runs real Tesseract on the fixtures against `expected.json`; it skips when the binary or the files
  are missing (CI today), and prints field names only, never values.
- **Duplicate fixtures**: 02 and 04 (same transfer, different bytes) and 01/08 (English and Arabic
  UI) are caught by reference. 05 and 06 are the same transfer, but 06 has no reference and shows
  a different screen, so nothing links them; that is accepted (see §3 "Duplicates").
- **Feature tests**: submit → job → event, idempotency, the disabled-module path in
  Affiliate, HMAC auth on the internal API, and signed URL expiry. Storage uses
  `Storage::fake`.
- **Accuracy gate before rollout**: 100% field accuracy on the 6 fixtures, and ≥ 98%
  on a larger labelled set of real receipts (at least 50 across InstaPay, Vodafone
  Cash and bank apps) collected during shadow mode, counting a field right only when both engines agree on it.
  Shadow mode also measures how often the second engine sends a match to a person, and whether `fallback` would
  be enough.
- **Second engine tests**: `RapidOcrEngineTest` fakes the sidecar (PNG sent, never the upload; PDF page and temp
  files removed; errors throw). `SubmitVerificationTest` covers every mode: agreement, a different amount, a missing
  phone, the sidecar down (review, warning, row completed), a mistyped mode (`always`), `off`, and `fallback`
  leaving a sure reading alone, filling a missing phone, confirming or not an unsure amount.
  `RealReceiptOcrTest` runs both engines on the real receipts when `TRANSACTION_VERIFICATION_RAPIDOCR_URL` answers.
- The run commands follow CLAUDE.md: `--testsuite=Modules --filter TransactionVerification`,
  and paratest for the gate. The new module's directory must appear in only one
  testsuite.

## 10. Rollout

**Re-cut 2026-09-29** into four phases (PR 1 below was split in two):
1. Foundation: skeleton, data model, contracts, `AmountParser`, `Matcher`, `DuplicateDetector` (sha256 + reference), null OCR. **Done.**
2. OCR, split in three:
   - **2a**: Tesseract engine, GD preprocessing at several thresholds, InstaPay parser (both layouts), `DateParser`, parser tests on recorded OCR output with synthetic values. **Done**: 9/9 fixtures (English and Arabic UI) read exactly.
   - **2b**: ~~pHash near-duplicates~~ dropped after measuring (§3 "Duplicates"); orientation moved to 2c.
   - **2c**: PDF (`pdftoppm`), EXIF orientation, Tesseract in the Docker image, default engine `tesseract`. **Done**: the image's Tesseract (Debian 13, 5.5.0) reads the 9 fixtures exactly; a receipt saved as PDF reads exactly.
   - **2d**: bank, card and mobile-wallet receipts (bare "To", account number), RapidOCR second engine (`ocr_rapid` sidecar, `second_engine.mode`, default `always`), threshold 0.90. **Done**: both engines agree on every field of all 15 real receipts. Deploy needs up to ~1.5 GB free RAM on the host for the sidecar (0.4 GB idle, capped at 2 GB).
3. Internal HTTP API, OpenAPI, `reprocess()`, `temporaryFileUrl()`. **Done**, off by default
   (`TRANSACTION_VERIFICATION_API_ENABLED`) until a caller exists; see *Update (phase 3)* in §5.
4. Affiliate integration behind a flag, then shadow mode. **Integration done**, off by default
   (`TRANSACTION_VERIFICATION_ENABLED`); see *Update (phase 4)* in §8. **Shadow mode next:** switch it on after
   phases 2c/2d are deployed, then label with `transaction-verification:shadow-report`.

The original sequence, for reference:

1. **PR 1**: module skeleton, migrations, contracts/DTOs/enums, normalisers, matcher,
   duplicate detector, Tesseract engine, InstaPay parser, fixtures, deptrac layers,
   boundary test, Docker packages. Nothing calls it yet.
2. **PR 2**: internal HTTP API and OpenAPI export, `reprocess` command.
3. **PR 3**: Affiliate integration (§8) behind `TRANSACTION_VERIFICATION_ENABLED=false`.
4. **Shadow mode** for about 2 weeks: enable it and log verdicts only. Label about
   50 receipts by hand and measure against the accuracy gate.
5. ~~Decide on PaddleOCR (sidecar PR) or keep Tesseract.~~ Done early (phase 2d: RapidOCR alongside Tesseract).
   Add parsers for any other app layouts seen in shadow mode (Vodafone Cash, bank apps).

Config after phase 1: `disk`, `folder`, `confidence_threshold`, `hmac_key`, `queue`, `stale_minutes`. The
full target list below arrives with the phases that need it (`enabled` in phase 4, engines in phase 2).

Target config (`config/transaction-verification.php`, all via env): `enabled`,
`disk` (default `hetzner`), `folder` (default `transaction-verifications`),
`engine` (`tesseract|null`), `tesseract.binary`, `tesseract.pdftoppm_binary`,
`tesseract.langs` (`ara+eng`), `second_engine.mode` (`always|fallback|off`, default `always`),
`second_engine.url` (`http://ocr_rapid:8080`), `second_engine.timeout` (60), `ollama.url` / `model` (not built),
`confidence_threshold` (0.90), `hmac_key`, `queue`, `stale_minutes` (15).
*Update (phase 3):* plus `api.enabled` (false) and `api.consumers` (key id → secrets and IP allowlist).
*Update (phase 4):* `disk` now defaults to `hetzner_receipts`: the same bucket and credentials as `hetzner`, plus
HTTP timeouts (5 s connect, 20 s per attempt, one retry), so a hung upload fails instead of holding a PHP worker, and
an upload that fails after its object landed deletes it (about 40 s per call, so up to about 80 s when the store is down).
Gift videos keep `hetzner`, which has no timeouts (large files).

## 11. Open questions (defaults in bold, so work can start without answers)

1. ~~**Amounts have piastres, payouts are whole EGP.**~~ **Resolved** (§2): whole amounts
   print without decimals, so exact matching holds.
2. **Storage.** "The S3 profile image storage": creator avatars
   (`RehostImportedAvatarJob`) actually use the **`public`** disk, because avatars
   must be publicly readable. Receipts contain personal data and must not be public.
   Default: **the private `hetzner` S3 bucket (same bucket and credentials as
   `GiftVideoStorage`), folder `transaction-verifications/`, served only through
   signed URLs.**
3. **Bulk mark-paid** with one shared proof. Default: **not submitted in v1.** Later
   option: accept a per-row screenshot list in the bulk form.
4. ~~**Fixtures contain real people's phone numbers and names** (recipients). Committing
   them keeps that data in git history forever. Default: **commit them with the
   recipient block blurred and the phone digits replaced by synthetic numbers in the
   same font.** Alternative: keep the originals outside the repo.~~ **Resolved
   (2026-09-30): never committed.** The real screenshots stay git-ignored on the developer's machine; CI tests the
   parser on recorded OCR text with every personal value made up, and the real-image test skips without them.
5. **Module name.** Default: **`TransactionVerification`** (other ideas:
   `ReceiptVerification`, `PaymentProof`).
6. **Local fallback VLM.** Default: **not deployed in v1** (no GPU box). Revisit
   after shadow mode.
7. **Two copies of every payout receipt, both kept forever** (raised in phase 4). Affiliate keeps its receipt on the
   app server (`local` disk, `affiliate-payouts/`, bind-mounted in `docker-compose.yml`) as the payout's record; once
   the flag is on, the module stores its own copy in the bucket (Q8: kept forever, no pruning). The module needs its
   own copy (the job reads it later, reprocess reads it again, API callers have no other copy). Option: delete the
   module's copy after N days; duplicate detection still works (it compares stored hashes), but old receipts can no
   longer be re-read. Default: **keep both; decide after shadow mode**, with real file sizes and reprocess usage.
