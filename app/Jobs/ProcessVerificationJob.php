<?php

namespace Modules\TransactionVerification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\TransactionVerification\Services\TransactionVerificationService;

/** Runs OCR and matching for one verification. Safe to dispatch twice: the service claims the row atomically. */
class ProcessVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $verificationId) {}

    public function handle(TransactionVerificationService $service): void
    {
        $service->process($this->verificationId);
    }
}
