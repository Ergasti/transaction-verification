<?php

namespace Modules\TransactionVerification\Http\Controllers\Internal;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;
use Modules\TransactionVerification\Services\TransactionVerificationService;

/** Service-to-service API over the module's public interface. */
class VerificationController
{
    // What the engines open: GD reads the images, PdfPage renders a PDF's first page.
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    // Response shapes for the spec: inference alone types the keyed maps (checks, extracted) as lists.
    private const ERR = 'array{success: bool, error: string, message: string}';

    private const ITEM = 'array{uuid: string, subject_type: string, subject_id: string, status: string, verdict: string|null, checks: array<string, array<string, mixed>>, duplicate_of: list<string>, extracted: array<string, string|int|null>, error: string|null}';

    private const ONE = 'array{success: bool, data: '.self::ITEM.'}';

    private const PAGE = 'array{success: bool, data: array{items: list<'.self::ITEM.'>, meta: array{current_page: int, per_page: int, last_page: int, total: int}}}';

    public function __construct(private readonly TransactionVerificationService $verifier) {}

    /** Check a receipt now. The file travels base64-encoded inside the JSON, so the signature covers it; it is never stored. */
    #[ResponseDoc(status: 200, type: self::ONE, description: 'Checked: the verdict (or the verification already made with this idempotency key).')]
    #[ResponseDoc(status: 401, type: self::ERR, description: 'Missing, wrong, expired or reused signature.')]
    #[ResponseDoc(status: 403, type: self::ERR, description: 'Caller IP not allowed.')]
    #[ResponseDoc(status: 415, type: self::ERR, description: 'The body is not JSON (error: json_required).')]
    #[ResponseDoc(status: 422, type: self::ERR, description: 'Invalid body or file (error: validation, file_invalid, file_too_large, file_type).')]
    #[ResponseDoc(status: 429, type: self::ERR, description: 'More than 30 writes a minute.')]
    #[ResponseDoc(status: 503, type: self::ERR, description: 'The API is switched off.')]
    #[BodyParameter(name: 'context', type: 'array<string, mixed>|null', description: 'Free-form object stored with the verification, e.g. who uploaded it.')]
    public function store(Request $request): JsonResponse
    {
        // PHP unpacks a form body before the app sees it, so the signature would cover an empty body.
        if (! $request->isJson()) {
            return response()->json(['success' => false, 'error' => 'json_required', 'message' => 'Send the receipt as a JSON body.'], 415);
        }

        try {
            $data = $request->validate([
                'subject_type' => ['required', 'string', 'max:64'],
                'subject_id' => ['required', 'string', 'max:64'],
                'merchant_id' => ['nullable', 'integer', 'min:1'],
                'expected_amount_minor' => ['required', 'integer', 'min:1'],
                'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
                'expected_destination' => ['required', 'array'],
                'expected_destination.type' => ['required', 'string', 'in:'.implode(',', [ExpectedDestination::PHONE, ExpectedDestination::INSTAPAY_HANDLE, ExpectedDestination::BANK])],
                'expected_destination.value' => ['required', 'string', 'max:255'],
                'idempotency_key' => ['required', 'string', 'max:255'],
                'context' => ['nullable', 'array'],
                'file_base64' => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            return $this->invalid('validation', (string) $e->validator->errors()->first());
        }

        $limit = (int) config('transaction-verification.max_file_bytes');

        // Base64 is 4 characters per 3 bytes: an encoding past the limit is refused before it is decoded.
        if (strlen($data['file_base64']) > intdiv($limit + 2, 3) * 4) {
            return $this->invalid('file_too_large', 'The receipt is larger than the limit.');
        }

        $bytes = base64_decode($data['file_base64'], true);

        if ($bytes === false || $bytes === '') {
            return $this->invalid('file_invalid', 'file_base64 is not valid base64.');
        }

        if (strlen($bytes) > $limit) {
            return $this->invalid('file_too_large', 'The receipt is larger than the limit.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::MIMES, true)) {
            return $this->invalid('file_type', 'The receipt must be a JPEG, PNG, WebP or PDF.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tv_api_');

        try {
            file_put_contents($tmp, $bytes);
            $result = $this->verifier->submit(new VerificationRequest(
                subjectType: $data['subject_type'],
                subjectId: $data['subject_id'],
                expectedAmountMinor: $data['expected_amount_minor'],
                expectedDestination: new ExpectedDestination($data['expected_destination']['type'], $data['expected_destination']['value']),
                // Test mode: the file was written here, not uploaded through PHP.
                file: new UploadedFile($tmp, 'receipt', $mime, UPLOAD_ERR_OK, true),
                idempotencyKey: $data['idempotency_key'],
                merchantId: $data['merchant_id'] ?? null,
                currency: $data['currency'] ?? 'EGP',
                context: $data['context'] ?? [],
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid('validation', $e->getMessage());
        } finally {
            @unlink($tmp);
        }

        return response()->json(['success' => true, 'data' => $this->present($result)]);
    }

    /** A subject's verifications, newest first. */
    #[ResponseDoc(status: 200, type: self::PAGE, description: 'One page of verifications.')]
    #[ResponseDoc(status: 401, type: self::ERR, description: 'Missing, wrong, expired or reused signature.')]
    #[ResponseDoc(status: 403, type: self::ERR, description: 'Caller IP not allowed.')]
    #[ResponseDoc(status: 422, type: self::ERR, description: 'subject_type or subject_id missing.')]
    #[ResponseDoc(status: 429, type: self::ERR, description: 'More than 600 reads a minute.')]
    #[ResponseDoc(status: 503, type: self::ERR, description: 'The API is switched off.')]
    #[QueryParameter(name: 'per_page', type: 'int', description: '1-100, default 20.')]
    #[QueryParameter(name: 'page', type: 'int', description: 'Page number, default 1.')]
    public function index(Request $request): JsonResponse
    {
        try {
            $query = $request->validate([
                'subject_type' => ['required', 'string', 'max:64'],
                'subject_id' => ['required', 'string', 'max:64'],
            ]);
        } catch (ValidationException $e) {
            return $this->invalid('validation', (string) $e->validator->errors()->first());
        }

        $page = $this->verifier->history($query['subject_type'], $query['subject_id'], min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json(['success' => true, 'data' => [
            'items' => array_map(fn (VerificationResult $result) => $this->present($result), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]]);
    }

    /** One verification: status, verdict, checks and what was read (masked). */
    #[ResponseDoc(status: 200, type: self::ONE, description: 'The verification.')]
    #[ResponseDoc(status: 401, type: self::ERR, description: 'Missing, wrong, expired or reused signature.')]
    #[ResponseDoc(status: 403, type: self::ERR, description: 'Caller IP not allowed.')]
    #[ResponseDoc(status: 404, type: self::ERR, description: 'No verification with this uuid.')]
    #[ResponseDoc(status: 429, type: self::ERR, description: 'More than 600 reads a minute.')]
    #[ResponseDoc(status: 503, type: self::ERR, description: 'The API is switched off.')]
    public function show(string $uuid): JsonResponse
    {
        $result = $this->verifier->find($uuid);

        return $result ? response()->json(['success' => true, 'data' => $this->present($result)]) : $this->notFound();
    }

    private function invalid(string $error, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $error, 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'verification_not_found', 'message' => 'No verification with this uuid.'], 404);
    }

    private function present(VerificationResult $result): array
    {
        return [
            'uuid' => $result->uuid,
            'subject_type' => $result->subjectType,
            'subject_id' => $result->subjectId,
            'status' => $result->status->value,
            'verdict' => $result->verdict?->value,
            'checks' => (object) $result->checks,
            'duplicate_of' => $result->duplicateOf,
            'extracted' => (object) $result->extracted,
            // The stored error can quote any exception (SQL included); the detail stays in the row.
            'error' => $result->error === null ? null : 'The receipt could not be read. Check it by hand.',
        ];
    }
}
