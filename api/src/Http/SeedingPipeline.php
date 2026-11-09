<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\InitialOperatorConfig;
use Citations\InitialOperatorSeeder;

/**
 * Request-pipeline stage that idempotently seeds the initial operator account
 * from the setup configuration at the start of request processing, then
 * delegates to the wrapped pipeline (Requirements 17.2, 17.3, 17.5; design
 * "Einrichtung des initialen Betreiber-Accounts").
 *
 * Wiring the {@see InitialOperatorSeeder} here keeps the seed INSIDE
 * {@see FrontController::run()}'s error boundary, so a config/DB failure during
 * seeding maps to a generic 500 that leaks no credentials, DSN, or SQL (15.7).
 * The seeder is idempotent: once an operator exists it changes nothing (17.3),
 * so running it on every request is safe and cheap.
 *
 * The configuration is provided as a lazy supplier so reading it participates
 * in the same error boundary and is only touched when a request is processed.
 */
final class SeedingPipeline implements RequestPipeline
{
    /** @var callable(): InitialOperatorConfig */
    private $configSupplier;

    /**
     * @param RequestPipeline                   $next           The wrapped stage.
     * @param InitialOperatorSeeder             $seeder         The idempotent seeder.
     * @param callable(): InitialOperatorConfig $configSupplier Lazy config resolver.
     */
    public function __construct(
        private readonly RequestPipeline $next,
        private readonly InitialOperatorSeeder $seeder,
        callable $configSupplier,
    ) {
        $this->configSupplier = $configSupplier;
    }

    public function dispatch(Request $request): Response
    {
        $this->seeder->seed(($this->configSupplier)());

        return $this->next->dispatch($request);
    }
}
