<?php

namespace Modules\TransactionVerification\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionVerificationDuplicate extends Model
{
    protected $table = 'transaction_verification_duplicates';

    protected $guarded = ['id'];

    // Same as TransactionVerification: the module's connection, from config.
    public function __construct(array $attributes = [])
    {
        $this->connection ??= config('transaction-verification.connection');
        parent::__construct($attributes);
    }
}
