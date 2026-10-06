<?php

return [
    // For callers (e.g. a payout flow): submit receipts only when on. Off by default.
    'enabled' => filter_var(env('TRANSACTION_VERIFICATION_ENABLED', false), FILTER_VALIDATE_BOOL),

    // A deciding field read below this confidence turns a match, or a failed check on it, into needs_review. Confidence is the share of the
    // Tesseract passes that read the field and agree (a pass that read nothing doesn't count), so at 0.90 no pass that
    // read it may disagree; on 'always' the second engine must also read it the same, or it drops to 0.
    'confidence_threshold' => (float) env('TRANSACTION_VERIFICATION_CONFIDENCE', 0.90),

    // Blind-index key; falls back to APP_KEY. Changing it orphans every stored hash.
    'hmac_key' => env('TRANSACTION_VERIFICATION_HMAC_KEY'),

    // Database connection for the receipt tables; unset = the app's default connection.
    'connection' => env('TRANSACTION_VERIFICATION_DB_CONNECTION'),

    // 'tesseract' needs the binaries (the Docker image has them); 'null' reads nothing, for a box without them.
    'engine' => env('TRANSACTION_VERIFICATION_ENGINE', 'tesseract'),

    // The ReceiptParser that reads OCR text into fields. Another app can plug in its own class.
    'parser' => \Modules\TransactionVerification\Services\Parsers\InstaPayReceiptParser::class,

    'tesseract' => [
        'binary' => env('TRANSACTION_VERIFICATION_TESSERACT_BINARY', 'tesseract'),
        // Renders page 1 of a PDF receipt to PNG (poppler-utils).
        'pdftoppm_binary' => env('TRANSACTION_VERIFICATION_PDFTOPPM_BINARY', 'pdftoppm'),
        'langs' => 'ara+eng',
        // [scale, threshold] per pass, measured on the fixtures (plan §3). The 1.5x pass sees the glyphs at
        // another size, so a dot every 2x pass loses ("1.50" as "150") splits the vote instead of passing.
        'passes' => [[2, 0.75], [2, 0.80], [2, 0.85], [1.5, 0.80]],
        // Seconds per pass (~1 s normally; one took over 12 s on a loaded box). The check runs inside the caller's
        // request, whose time limit still counts after the response: two rounds of passes plus RapidOCR fit easily.
        'timeout' => 15,
        // Passes read side by side, each on one core: 4 reads a receipt in about one pass's time. 1 = one after another.
        'parallel' => (int) env('TRANSACTION_VERIFICATION_TESSERACT_PARALLEL', 4),
        // On second_engine 'always': read the first this-many passes, and the rest only if they and RapidOCR don't
        // agree on the amount, destination and reference. 0 = always every pass.
        'early_passes' => (int) env('TRANSACTION_VERIFICATION_TESSERACT_EARLY_PASSES', 2),
    ],

    // RapidOCR in the ocr_rapid sidecar, an engine unrelated to Tesseract, so their errors don't coincide.
    // 'always': it reads every receipt, and a deciding field Tesseract read counts only if it reads the same.
    // 'fallback': it reads only when Tesseract is unsure (a deciding field missing or under the threshold);
    //   it then fills a missing field (decided by RapidOCR alone), or confirms an unsure one it reads the same.
    //   A confidently wrong Tesseract read is never checked in this mode.
    // 'off': Tesseract alone (a box without the sidecar).
    // Down, slow or failing, it reads nothing: on 'always' every match then waits for a person.
    'second_engine' => [
        'mode' => env('TRANSACTION_VERIFICATION_SECOND_ENGINE', 'always'),
        'url' => env('TRANSACTION_VERIFICATION_RAPIDOCR_URL', 'http://ocr_rapid:8080'),
        // Seconds: ~0.4 s per receipt on its 4 cores (anything bigger is read at 1600 px), one at a time. Short, so a hung
        // sidecar frees the caller's PHP worker soon.
        'timeout' => 20,
    ],

    // Largest receipt a check will read (bytes). Phone screenshots are well under 5 MB.
    'max_file_bytes' => (int) env('TRANSACTION_VERIFICATION_MAX_FILE_BYTES', 10 * 1024 * 1024),

    // A check untouched this long died mid-way (crash, time limit) and is marked failed. Must exceed the longest check.
    'stale_minutes' => (int) env('TRANSACTION_VERIFICATION_STALE_MINUTES', 15),

    // Service-to-service API (routes/internal.php). Off until a caller exists and its secret is set.
    'api' => [
        'enabled' => filter_var(env('TRANSACTION_VERIFICATION_API_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Key id => secrets (the current one, plus the previous one while rotating) and an optional IP allowlist.
        'consumers' => [
            env('TRANSACTION_VERIFICATION_API_KEY_ID', 'caller') => [
                'secrets' => array_values(array_filter([env('TRANSACTION_VERIFICATION_API_SECRET'), env('TRANSACTION_VERIFICATION_API_SECRET_PREV')])),
                'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRANSACTION_VERIFICATION_API_IPS', ''))))),
            ],
        ],
    ],
];
