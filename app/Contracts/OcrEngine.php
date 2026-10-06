<?php

namespace Modules\TransactionVerification\Contracts;

/** Turns a local image file into raw text. Swapping engines is a binding change. */
interface OcrEngine
{
    /** Raw text, or '' when nothing could be read. An engine that reads several times separates the passes with "\f". */
    public function read(string $localPath): string;

    public function name(): string;

    public function version(): string;
}
