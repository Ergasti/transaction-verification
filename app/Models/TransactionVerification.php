<?php

namespace Modules\TransactionVerification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\TransactionVerification\Enums\VerdictEnum;
use Modules\TransactionVerification\Enums\VerificationStatusEnum;

class TransactionVerification extends Model
{
    protected $table = 'transaction_verifications';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => VerificationStatusEnum::class,
        'verdict' => VerdictEnum::class,
        'attempts' => 'integer',
        // Personal data: the recipient's phone/handle and everything read off the screenshot.
        'expected_destination' => 'encrypted:array',
        'ocr_text' => 'encrypted',
        'second_ocr_text' => 'encrypted',
        'extracted' => 'encrypted:array',
        'checks' => 'array',
        'context' => 'array',
        'confidence' => 'decimal:3',
        'processed_at' => 'datetime',
    ];

    // Encrypted casts protect the column at rest, not toArray()/JSON output.
    protected $hidden = ['expected_destination', 'ocr_text', 'second_ocr_text', 'extracted'];

    // The property, not getConnectionName(): Eloquent also reads it directly. setConnection()/on() still win.
    public function __construct(array $attributes = [])
    {
        $this->connection ??= config('transaction-verification.connection');
        parent::__construct($attributes);
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid();
        });
    }
}
