<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

/**
 * Smoke test proving the property testsuite (Eris) is wired up and runs with
 * at least 100 iterations. Replaced by the Property 1..46 tests in later tasks.
 */
final class ScaffoldingPropertyTest extends PropertyTestCase
{
    public function testPropertyRunnerIsConfiguredWithAtLeast100Iterations(): void
    {
        self::assertGreaterThanOrEqual(100, $this->iterations());

        $this->limitTo($this->iterations());
        $this->forAll(\Eris\Generator\int())
            ->then(function (int $n): void {
                self::assertSame($n, $n + 0);
            });
    }
}
