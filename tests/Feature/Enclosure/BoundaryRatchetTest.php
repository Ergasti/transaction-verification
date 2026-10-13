<?php

namespace Modules\TransactionVerification\Tests\Feature\Enclosure;

use Modules\TransactionVerification\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The package stays independent: it never reaches a host module or an App\ class.
 * Grep-based, so it also covers routes and config. The host app checks its own side (helpers, public API).
 */
class BoundaryRatchetTest extends TestCase
{
    private const MAX_APP_REFERENCES = 0;

    public function test_module_never_references_another_module(): void
    {
        $scanned = 0;
        foreach ($this->phpFiles(['app', 'database', 'config']) as $path => $source) {
            $scanned++;
            // Any module but this one.
            $this->assertDoesNotMatchRegularExpression('/Modules\\\\(?!TransactionVerification\\\\)[A-Z]\w*\\\\/', $source, "{$path} reaches into another module.");
        }

        $this->assertGreaterThan(0, $scanned, 'No package files scanned — the paths are wrong.');
    }

    public function test_module_to_core_coupling_only_ever_shrinks(): void
    {
        $references = [];
        $scanned = 0;
        foreach ($this->phpFiles(['app']) as $source) {
            $scanned++;
            preg_match_all('/(?:^use |\\\\)(App\\\\[A-Za-z0-9_\\\\]+)/m', $source, $m);
            array_push($references, ...$m[1]);
        }

        $this->assertGreaterThan(0, $scanned, 'No package files scanned — the paths are wrong.');
        $this->assertLessThanOrEqual(self::MAX_APP_REFERENCES, count($references), 'TransactionVerification now references host classes: '.implode(', ', array_unique($references)));
    }

    public function test_the_connection_is_never_hard_coded(): void
    {
        // String values only (comments may mention it), incl. 'required|exists:portal.table' and 'database.connections.portal'.
        $found = [];
        $scanned = 0;
        foreach ($this->phpFiles(['app', 'database', 'routes']) as $path => $source) {
            $scanned++;
            foreach ($this->tokens($source) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/(?:^|[|:.])portal(?:[.|,]|$)/i', substr($token[1], 1, -1))) {
                    $found[] = "{$path}:{$token[2]}";
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No package files scanned — the paths are wrong.');
        $this->assertSame([], $found, "Hard-coded portal connection; use config('transaction-verification.connection').");
    }

    /** @return list<array{int, string, int}|string> tokens without whitespace and comments */
    private function tokens(string $source): array
    {
        return array_values(array_filter(token_get_all($source), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    /** @return iterable<string, string> path => source */
    private function phpFiles(array $dirs): iterable
    {
        $root = dirname(__DIR__, 3);
        foreach ($dirs as $dir) {
            if (! is_dir("{$root}/{$dir}")) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    yield $file->getPathname() => (string) file_get_contents($file->getPathname());
                }
            }
        }
    }
}
