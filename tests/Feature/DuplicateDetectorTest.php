<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Models\TransactionVerificationDuplicate;
use Modules\TransactionVerification\Services\DuplicateDetector;
use Modules\TransactionVerification\Tests\TestCase;

class DuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_file_for_another_subject_is_a_duplicate(): void
    {
        $first = $this->row('affiliate_payout', 1, sha: 'aaa');
        $second = $this->row('affiliate_payout', 2, sha: 'aaa');

        $hits = (new DuplicateDetector)->find($second, null);

        $this->assertSame([['id' => $first->id, 'uuid' => $first->uuid, 'method' => 'sha256']], $hits);
        $this->assertTrue(TransactionVerificationDuplicate::where(['verification_id' => $second->id, 'duplicate_of_id' => $first->id, 'method' => 'sha256'])->exists());
    }

    public function test_same_subject_resubmitted_is_not_a_duplicate(): void
    {
        $this->row('affiliate_payout', 1, sha: 'aaa');
        $again = $this->row('affiliate_payout', 1, sha: 'aaa');

        $this->assertSame([], (new DuplicateDetector)->find($again, null));
    }

    public function test_same_id_under_another_subject_type_is_a_different_subject(): void
    {
        $this->row('affiliate_payout', 1, sha: 'aaa');
        $other = $this->row('merchant_refund', 1, sha: 'aaa');

        $this->assertCount(1, (new DuplicateDetector)->find($other, null));
    }

    public function test_same_reference_in_a_different_file_is_a_duplicate(): void
    {
        // Fixtures 02 and 04: one transfer, two screenshots with different bytes.
        $first = $this->row('affiliate_payout', 1, sha: 'aaa', reference: 'ref-hash');
        $second = $this->row('affiliate_payout', 2, sha: 'bbb');

        $hits = (new DuplicateDetector)->find($second, 'ref-hash');

        $this->assertSame([['id' => $first->id, 'uuid' => $first->uuid, 'method' => 'reference']], $hits);
    }

    public function test_the_original_is_never_the_duplicate_of_a_later_receipt(): void
    {
        $original = $this->row('affiliate_payout', 1, sha: 'aaa');
        $this->row('affiliate_payout', 2, sha: 'aaa');

        // The original's job ran after the copy was inserted (queue backlog).
        $this->assertSame([], (new DuplicateDetector)->find($original, null));
    }

    public function test_same_reference_for_the_same_subject_is_not_a_duplicate(): void
    {
        $this->row('affiliate_payout', 1, sha: 'aaa', reference: 'ref-hash');
        $again = $this->row('affiliate_payout', 1, sha: 'bbb');

        $this->assertSame([], (new DuplicateDetector)->find($again, 'ref-hash'));
    }

    public function test_different_transfers_to_the_same_phone_are_not_duplicates(): void
    {
        $this->row('affiliate_payout', 1, sha: 'aaa', reference: 'ref-1');
        $second = $this->row('affiliate_payout', 2, sha: 'bbb');

        $this->assertSame([], (new DuplicateDetector)->find($second, 'ref-2'));
    }

    public function test_finding_twice_records_the_match_once(): void
    {
        $this->row('affiliate_payout', 1, sha: 'aaa');
        $second = $this->row('affiliate_payout', 2, sha: 'aaa');

        (new DuplicateDetector)->find($second, null);
        (new DuplicateDetector)->find($second, null);

        $this->assertSame(1, TransactionVerificationDuplicate::count());
    }

    private function row(string $subjectType, int $subjectId, string $sha, ?string $reference = null): TransactionVerification
    {
        return TransactionVerification::create([
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'status' => VerificationStatusEnum::COMPLETED,
            'expected_amount_minor' => 205000,
            'currency' => 'EGP',
            'expected_destination' => ['type' => 'phone', 'value' => '01000000001'],
            'expected_destination_hash' => str_repeat('0', 64),
            'file_disk' => 'hetzner',
            'file_path' => 'transaction-verifications/x.png',
            'file_mime' => 'image/png',
            'file_size' => 1,
            'file_sha256' => $sha,
            'reference_hash' => $reference,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
