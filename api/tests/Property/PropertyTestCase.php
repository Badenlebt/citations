<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base class for property-based tests.
 *
 * Centralizes the project-wide convention that every property test runs with
 * at least 100 iterations (see tasks.md / design.md). Concrete property tests
 * should start their quantifier with `$this->forAll(...)->withMaxIterations(
 * $this->iterations())` or call {@see self::iterations()} explicitly.
 *
 * The default can be overridden per environment via the ERIS_ITERATIONS env
 * variable (configured to 100 in phpunit.xml), but never drops below 100.
 */
abstract class PropertyTestCase extends TestCase
{
    use TestTrait;

    /**
     * Minimum number of iterations per property test (>= 100 by contract).
     */
    protected function iterations(): int
    {
        $configured = (int) ($_ENV['ERIS_ITERATIONS'] ?? getenv('ERIS_ITERATIONS') ?: 100);

        return max(100, $configured);
    }
}
