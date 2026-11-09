<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 25: Ungültige Quelle oder Sprache
 * verhindert Anlegen bzw. Änderung.
 *
 * For any non-existent source id or non-existent language id, both the create
 * and the edit path of {@see QuoteCreator} reject the operation with
 * INVALID_SOURCE resp. INVALID_LANGUAGE; on create no quote comes into
 * existence and on edit the already-stored quote stays byte-for-byte
 * unchanged. The text itself is always valid (non-empty, within the length
 * bound) so the only reason for rejection is the dangling reference under
 * test, isolating exactly the source/language validation contract.
 *
 * Validates: Requirements 8.3, 8.4, 9.3
 */
final class QuoteCreatorInvalidReferencePropertyTest extends PropertyTestCase
{
    /**
     * Property 25 on the create path: a create that names a non-existent
     * source (INVALID_SOURCE) or a non-existent language (INVALID_LANGUAGE)
     * is rejected with that exact code and stores no quote at all.
     */
    public function testCreateWithDanglingReferenceIsRejectedAndStoresNothing(): void
    {
        $this->forAll(
            // Non-empty, length-valid quote text, built from a shrinkable seed.
            Generator\seq(Generator\choose(33, 126)),
            Generator\choose(1, QuoteCreator::MAX_TEXT_LENGTH),
            // Positive offset added to the highest live id to guarantee a
            // reference that resolves to no existing row.
            Generator\choose(1, 10000),
            // Which reference is broken: 0 = source, 1 = language.
            Generator\choose(0, 1)
        )->then(function (array $textPicks, int $textLen, int $gap, int $broken): void {
            [$store, $quotes, $creator, $liveSourceId, $liveLanguageId] = $this->freshWorld();

            $text = $this->buildText($textPicks, $textLen);

            if ($broken === 0) {
                $sourceId = $liveSourceId + $gap;   // dangling source
                $languageId = $liveLanguageId;      // valid language
                $expected = ErrorCode::INVALID_SOURCE;
            } else {
                $sourceId = $liveSourceId;              // valid source
                $languageId = $liveLanguageId + $gap;   // dangling language
                $expected = ErrorCode::INVALID_LANGUAGE;
            }

            $before = $store->quotes;

            $result = $creator->create($text, $sourceId, $languageId);

            self::assertFalse($result->isSuccess, 'Create with a dangling reference must fail.');
            self::assertNull($result->id);
            self::assertSame(
                $expected,
                $result->error,
                sprintf('Expected %s on create.', $expected->value)
            );

            // No quote came into existence (8.3 / 8.4).
            self::assertSame(0, $quotes->count());
            self::assertSame($before, $store->quotes, 'Create must not change the quote store.');
        });
    }

    /**
     * Property 25 on the edit path: editing an EXISTING quote with a
     * non-existent source or language is rejected with the matching code and
     * leaves that quote completely unchanged (9.3).
     */
    public function testEditWithDanglingReferenceIsRejectedAndLeavesQuoteUnchanged(): void
    {
        $this->forAll(
            Generator\seq(Generator\choose(33, 126)),
            Generator\choose(1, QuoteCreator::MAX_TEXT_LENGTH),
            Generator\choose(1, 10000),
            Generator\choose(0, 1)
        )->then(function (array $textPicks, int $textLen, int $gap, int $broken): void {
            [$store, $quotes, $creator, $liveSourceId, $liveLanguageId] = $this->freshWorld();

            // Seed exactly one existing quote to be the target of the edit.
            $created = $creator->create('original text', $liveSourceId, $liveLanguageId);
            self::assertTrue($created->isSuccess);
            $quoteId = $created->id;

            $newText = $this->buildText($textPicks, $textLen);

            if ($broken === 0) {
                $sourceId = $liveSourceId + $gap;
                $languageId = $liveLanguageId;
                $expected = ErrorCode::INVALID_SOURCE;
            } else {
                $sourceId = $liveSourceId;
                $languageId = $liveLanguageId + $gap;
                $expected = ErrorCode::INVALID_LANGUAGE;
            }

            $before = $store->quotes;

            $result = $creator->edit($quoteId, $newText, $sourceId, $languageId);

            self::assertFalse($result->isSuccess, 'Edit with a dangling reference must fail.');
            self::assertNull($result->id);
            self::assertSame(
                $expected,
                $result->error,
                sprintf('Expected %s on edit.', $expected->value)
            );

            // The existing quote is byte-for-byte unchanged (9.3).
            self::assertSame($before, $store->quotes, 'Edit must not change the quote store.');
            self::assertSame('original text', $quotes->findById($quoteId)['text']);
        });
    }

    /**
     * Build a fresh, consistent world with one live source and one live
     * language, returning the store, the quote repo, the creator under test
     * and the two live reference ids.
     *
     * @return array{0: InMemoryStore, 1: InMemoryQuoteRepository, 2: QuoteCreator, 3: int, 4: int}
     */
    private function freshWorld(): array
    {
        $store = new InMemoryStore();
        $quotes = new InMemoryQuoteRepository($store);
        $sources = new InMemorySourceRepository($store);
        $languages = new InMemoryLanguageRepository($store);

        $sourceId = $sources->insert('Seneca', []);
        $languageId = $languages->insert('Deutsch', 'deutsch');

        $creator = new QuoteCreator($quotes, $sources, $languages);

        return [$store, $quotes, $creator, $sourceId, $languageId];
    }

    /**
     * Compose a non-empty, length-valid quote text of `$len` printable ASCII
     * characters from the shrinkable picks. A leading/trailing non-space char
     * is guaranteed so the trimmed text never collapses to empty, keeping the
     * only possible rejection reason the dangling reference under test.
     *
     * @param list<int> $picks printable-ASCII code points (33..126)
     */
    private function buildText(array $picks, int $len): string
    {
        $len = max(1, min($len, QuoteCreator::MAX_TEXT_LENGTH));

        $out = '';
        $i = 0;
        while (mb_strlen($out, 'UTF-8') < $len) {
            $code = $picks === [] ? 65 : $picks[$i % count($picks)];
            $out .= chr($code);
            $i++;
        }

        return mb_substr($out, 0, $len, 'UTF-8');
    }
}
