<?php

namespace Modules\TransactionVerification\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;
use Modules\TransactionVerification\Models\TransactionVerification;
use Modules\TransactionVerification\Models\TransactionVerificationDuplicate;

/**
 * Finds earlier receipts for a DIFFERENT subject with the same file or the same transfer reference.
 * The same subject re-submitted is not a duplicate, and the phone is never used (one creator, many transfers).
 */
class DuplicateDetector
{
    /** @return list<array{id: int, uuid: string, method: string}> */
    public function find(TransactionVerification $row, ?string $referenceHash): array
    {
        // Earlier rows only: the original receipt never becomes the duplicate, whatever order the checks finish in.
        $others = $this->otherSubjects($row)->where('id', '<', $row->id);

        $hits = [];

        foreach ((clone $others)->where('file_sha256', $row->file_sha256)->get(['id', 'uuid']) as $match) {
            $hits[] = ['id' => $match->id, 'uuid' => $match->uuid, 'method' => 'sha256'];
        }

        if ($referenceHash !== null) {
            foreach ((clone $others)->where('reference_hash', $referenceHash)->get(['id', 'uuid']) as $match) {
                $hits[] = ['id' => $match->id, 'uuid' => $match->uuid, 'method' => 'reference'];
            }
        }

        foreach ($hits as $hit) {
            TransactionVerificationDuplicate::firstOrCreate([
                'verification_id' => $row->id,
                'duplicate_of_id' => $hit['id'],
                'method' => $hit['method'],
            ]);
        }

        return $hits;
    }

    /**
     * Later copies of this row's transfer that ran before its reference was known, so they missed it.
     *
     * @return list<int>
     */
    public function laterCopies(TransactionVerification $row, string $referenceHash): array
    {
        return $this->otherSubjects($row)
            ->where('id', '>', $row->id)
            ->where('reference_hash', $referenceHash)
            ->whereIn('status', [VerificationStatusEnum::PROCESSING->value, VerificationStatusEnum::COMPLETED->value])
            ->whereNotIn('id', TransactionVerificationDuplicate::where('duplicate_of_id', $row->id)->select('verification_id'))
            ->pluck('id')
            ->all();
    }

    private function otherSubjects(TransactionVerification $row): Builder
    {
        return TransactionVerification::query()
            ->where(fn ($q) => $q->where('subject_type', '!=', $row->subject_type)
                ->orWhere('subject_id', '!=', $row->subject_id));
    }
}
