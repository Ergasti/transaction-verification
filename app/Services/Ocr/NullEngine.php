<?php

namespace Modules\TransactionVerification\Services\Ocr;

use Modules\TransactionVerification\Contracts\OcrEngine;

/** Phase-1 placeholder: reads nothing, so every receipt comes out unreadable until a real engine is bound. */
class NullEngine implements OcrEngine
{
    public function read(string $localPath): string
    {
        return '';
    }

    public function name(): string
    {
        return 'null';
    }

    public function version(): string
    {
        return '0';
    }
}
