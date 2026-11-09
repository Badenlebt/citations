<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 33: Validierung der Link-URL.
 *
 * Für jede gesetzte Link-URL einer Quelle gilt: Nur Werte mit dem Präfix
 * "http://" oder "https://" (bis 2000 Zeichen nach Trim) werden akzeptiert und
 * gespeichert; eine gesetzte Link-URL ohne http(s)://-Präfix wird mit
 * INVALID_LINK_URL abgewiesen, und eine Link-URL über 2000 Zeichen mit
 * INPUT_TOO_LONG — jeweils ohne Zustandsänderung. Code under test:
 * {@see SourceCreator::create()} gegen das in-memory
 * {@see InMemorySourceRepository}.
 *
 * The property covers three disjoint classes of input, chosen per iteration:
 * a valid prefixed URL (accepted, round-tripped), a non-http(s) prefixed URL
 * (INVALID_LINK_URL, no source), and an over-long URL (INPUT_TOO_LONG, no
 * source). Note the length check precedes the prefix check in the mutation
 * layer, so the over-long case is generated with a valid prefix to isolate the
 * length rule (10.4, 10.5, 10.7).
 *
 * Validates: Requirements 10.4, 10.5
 */
final class SourceCreatorLinkUrlValidationPropertyTest extends PropertyTestCase
{
    public function testLinkUrlValidationCreate(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // 0 = valid prefixed, 1 = missing prefix, 2 = over-long.
            Generator\choose(0, 2),
            Generator\bool(),
            Generator\string()
        )->then(function (int $kind, bool $https, string $tail): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $creator = new SourceCreator($sources);
            $before = array_keys($store->sources);

            if ($kind === 0) {
                // A valid, in-bound URL with an http(s):// prefix.
                $url = ($https ? 'https://' : 'http://') . 'example.test/' . $tail;
                if (mb_strlen($url, 'UTF-8') > SourceCreator::MAX_LINK_URL_LENGTH) {
                    return;
                }

                $result = $creator->create('Autor', null, null, null, $url);
                self::assertTrue($result->isSuccess, 'A valid prefixed link URL must be accepted.');
                $record = $sources->findById($result->id);
                self::assertNotNull($record);
                self::assertSame($url, $record['linkUrl'], 'The link URL must round-trip unchanged.');

                return;
            }

            if ($kind === 1) {
                // A non-empty value that does NOT start with http:// or https://.
                $url = 'ftp://host/' . $tail;
                $result = $creator->create('Autor', null, null, null, $url);
                self::assertFalse($result->isSuccess, 'A link URL without http(s):// must be rejected.');
                self::assertSame(ErrorCode::INVALID_LINK_URL, $result->error);
                self::assertNull($result->id);
                self::assertSame($before, array_keys($store->sources), 'invalid link URL must not create a source');

                return;
            }

            // kind === 2: a valid prefix but over the 2000-character bound, so
            // the length rule applies (it is checked before the prefix rule).
            $prefix = $https ? 'https://' : 'http://';
            $url = $prefix . str_repeat('a', SourceCreator::MAX_LINK_URL_LENGTH + 1);
            $result = $creator->create('Autor', null, null, null, $url);
            self::assertFalse($result->isSuccess, 'An over-long link URL must be rejected.');
            self::assertSame(ErrorCode::INPUT_TOO_LONG, $result->error);
            self::assertNull($result->id);
            self::assertSame($before, array_keys($store->sources), 'over-long link URL must not create a source');
        });
    }
}
