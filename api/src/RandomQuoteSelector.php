<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\QuoteRepositoryInterface;

/**
 * Random-quote selection against the stored stock (Requirements 3.1, 3.3;
 * design "`GET /api/random`" and "Zufallsauswahl mit injizierbarem RNG").
 *
 * This is the composition layer the `GET /api/random` endpoint (task 12.5) will
 * call. It wires together the three collaborators without adding any new
 * selection logic of its own:
 *   - the injected {@see RandomNumberGenerator} supplies one normalized draw in
 *     [0, 1), the only I/O/entropy step (and the seam that makes the whole
 *     selection deterministic under test);
 *   - the pure {@see RandomSelector::select()} maps that draw onto an index in
 *     the stock of {@see QuoteRepositoryInterface::count()} quotes;
 *   - {@see QuoteRepositoryInterface::findByIndex()} resolves that index to the
 *     concrete joined quote record (text, language, source).
 *
 * For a non-empty stock this returns EXACTLY ONE quote with its text, language
 * and source (3.1). For an empty stock the pure selector yields no index and
 * this returns null, which the endpoint renders as the `NO_QUOTES` 404 (3.3).
 * The index produced for a non-empty stock is always in range, so the lookup
 * never misses; no I/O happens here beyond the generator draw and the two
 * repository reads.
 */
final class RandomQuoteSelector
{
    public function __construct(
        private readonly QuoteRepositoryInterface $quotes,
        private readonly RandomNumberGenerator $rng,
    ) {
    }

    /**
     * Pick one random quote from the current stock.
     *
     * Reads the stock size once, maps a single injected draw to an index via
     * the pure {@see RandomSelector::select()}, and resolves it against the same
     * stable ordering {@see QuoteRepositoryInterface::findByIndex()} uses.
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string, linkUrl: string|null}}|null
     *         The selected quote (3.1), or null when the stock is empty so the
     *         endpoint can answer `NO_QUOTES` (3.3).
     */
    public function pick(): ?array
    {
        $index = RandomSelector::select($this->quotes->count(), $this->rng->next());

        // Empty stock: the pure selector returned null (NO_QUOTES, 3.3).
        if ($index === null) {
            return null;
        }

        return $this->quotes->findByIndex($index);
    }
}
