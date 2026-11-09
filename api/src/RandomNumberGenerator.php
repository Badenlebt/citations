<?php

declare(strict_types=1);

namespace Citations;

/**
 * Narrow randomness boundary that yields a single draw in the half-open
 * interval [0, 1) (design "Zufallsauswahl mit injizierbarem RNG",
 * Requirements 3.1, 3.2).
 *
 * The whole point of this seam is testability: the I/O-bound entropy source is
 * injected behind this interface so the random-quote composition
 * ({@see RandomQuoteSelector}) can be driven with a deterministic, fixed draw
 * under test, while the real endpoint uses a production implementation backed
 * by {@see random_int()} (see {@see SystemRandomNumberGenerator}). The index
 * MAPPING from that draw stays in the pure {@see RandomSelector::select()}; this
 * boundary only produces the normalized draw, it performs no mapping and no I/O
 * beyond obtaining entropy.
 */
interface RandomNumberGenerator
{
    /**
     * Draw a random value in the half-open interval [0, 1).
     *
     * The returned value is suitable to hand straight to
     * {@see RandomSelector::select()} as its injected randomness. It is 0.0 or
     * greater and strictly less than 1.0.
     */
    public function next(): float;
}
