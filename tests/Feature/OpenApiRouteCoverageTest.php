<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Modules\TransactionVerification\Tests\TestCase;

/** Every route of the internal API must be in the committed spec, or a caller can't know it exists. */
class OpenApiRouteCoverageTest extends TestCase
{
    private const PREFIX = 'api/internal/transaction-verification/v1';

    private const SPEC = 'openapi/transaction-verification-internal.yaml';

    public function test_every_internal_api_route_is_in_the_spec(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), self::PREFIX)) {
                foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                    $routes[] = strtolower($method).' '.$this->shape(substr($route->uri(), strlen(self::PREFIX)));
                }
            }
        }

        $documented = [];

        foreach (Yaml::parseFile(__DIR__.'/../../'.self::SPEC)['paths'] ?? [] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtolower((string) $method).' '.$this->shape((string) $path);
            }
        }

        // Not vacuous: a prefix typo would match nothing and pass.
        $this->assertCount(5, $routes);
        $this->assertSame([], array_values(array_diff($routes, $documented)), 'Routes missing from '.self::SPEC.': re-export it from the host app (openapi:export).');
    }

    /** Param names may differ between the route and the spec; only the shape counts. */
    private function shape(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/')) ?? $path;
    }
}
