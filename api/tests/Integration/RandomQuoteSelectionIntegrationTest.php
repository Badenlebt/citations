<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use Citations\RandomQuoteSelector;
use Citations\Repository\PdoLanguageRepository;
use Citations\Repository\PdoQuoteRepository;
use Citations\Repository\PdoSourceRepository;
use Citations\SystemRandomNumberGenerator;

/**
 * End-to-end integration test for the random-quote I/O path (task 11.4;
 * Requirements 3.1, 3.2) against a real MariaDB.
 *
 * It seeds several quotes through the PDO repositories, then drives
 * {@see RandomQuoteSelector} with the production {@see SystemRandomNumberGenerator}
 * across many iterations. Over many calls the selection yields more than one
 * distinct quote (3.2 "jedes Zitat ist grundsaetzlich erreichbar", observed
 * end-to-end) and every returned record is a complete joined quote with text,
 * language and source (3.1).
 *
 * The iteration count and distinctness threshold are chosen so the test is
 * statistically robust: with 12 seeded quotes and 400 draws from a uniform RNG,
 * seeing only a single distinct id has a probability of (1/12)^399, which is far
 * below any practical flakiness threshold. We assert the much weaker "> 1
 * distinct" which is effectively certain.
 */
final class RandomQuoteSelectionIntegrationTest extends IntegrationTestCase
{

    public function testRandomSelectionOverManyCallsYieldsDifferentCompleteQuotes(): void
    {
        $pdo = $this->requireDatabase();

        $quotes = new PdoQuoteRepository($pdo);
        $sources = new PdoSourceRepository($pdo);
        new PdoLanguageRepository($pdo); // ensure the class is loadable alongside the others

        $languageId = $this->seedLanguage($pdo, 'Deutsch', 'deutsch');
        $unknownSourceId = $sources->findUnknown()['id'];
        $namedSourceId = $sources->insert('Integration Autor', ['linkUrl' => 'https://example.test/a']);

        // Seed a dozen distinct quotes across two sources so selection can vary.
        $seededIds = [];
        for ($i = 1; $i <= 12; $i++) {
            $sourceId = $i % 2 === 0 ? $namedSourceId : $unknownSourceId;
            $seededIds[] = $quotes->insert('Zitat Nummer ' . $i, $sourceId, $languageId);
        }
        sort($seededIds);

        $this->assertSame(12, $quotes->count(), 'All seeded quotes must be counted.');

        $selector = new RandomQuoteSelector($quotes, new SystemRandomNumberGenerator());

        $seen = [];
        $iterations = 400;
        for ($i = 0; $i < $iterations; $i++) {
            $picked = $selector->pick();

            $this->assertNotNull($picked, 'A non-empty stock must always yield a quote (3.2/3.3).');

            // 3.1: each returned record is complete — text, language, source.
            $this->assertArrayHasKey('id', $picked);
            $this->assertIsInt($picked['id']);
            $this->assertNotSame('', $picked['text']);
            $this->assertNotSame('', $picked['languageName']);
            $this->assertSame($languageId, $picked['languageId']);
            $this->assertArrayHasKey('source', $picked);
            $this->assertNotSame('', $picked['source']['name']);
            $this->assertContains(
                $picked['id'],
                $seededIds,
                'Every selected id must be one of the seeded quotes.',
            );

            $seen[$picked['id']] = true;
        }

        // 3.2 end-to-end: over many calls more than one distinct quote appears.
        $this->assertGreaterThan(
            1,
            count($seen),
            'Random selection over many calls should return more than one distinct quote.',
        );
    }
}
