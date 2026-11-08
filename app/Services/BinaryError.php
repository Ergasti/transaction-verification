<?php

namespace Modules\TransactionVerification\Services;

/**
 * Why a binary would not run, as a sentence naming the fix. `sh` reports a command it cannot find as 127 and
 * one it cannot execute as 126 -- 126 is also how a `noexec` mount surfaces, which is otherwise invisible.
 */
final class BinaryError
{
    /** @return ?string null when the exit code says nothing about the binary itself, so the caller reports its own failure. */
    public static function explain(string $binary, ?int $exitCode, string $install): ?string
    {
        return match ($exitCode) {
            127 => "{$binary} was not found. {$install}",
            126 => "{$binary} is there but would not run. Check that it is executable and that its filesystem is not mounted noexec.",
            default => null,
        };
    }
}
