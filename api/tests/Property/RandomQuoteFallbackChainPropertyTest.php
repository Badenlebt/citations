<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 2: Rückfallkette der Sprachauswahl
 *
 * Für jeden Zitatbestand, jede Sprachzuordnung, jede gültige Oberflächensprache
 * ui ∈ {de, en} und jeden RNG-Wert r entspricht das von
 * {@see RandomQuoteSelector::pick()} gewählte Zitat dem Referenzmodell:
 * (a) Zuordnung für ui mit n > 0 Zitaten => Index floor(r*n) im Teilbestand
 * (id-aufsteigend); (b) sonst Zuordnung für en mit Zitaten => dito;
 * (c) sonst Gesamtbestand.
 *
 * Validates: Requirements 6.8, 6.9, 6.10
 */
final class RandomQuoteFallbackChainPropertyTest extends PropertyTestCase
{
    public function testSelectionFollowsFallbackChainReferenceModel(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 4),
            Generator\seq(Generator\choose(0, 3)),
            Generator\choose(-1, 3),
            Generator\choose(-1, 3),
            Generator\choose(0, 999_999),
            Generator\elements('de', 'en')
        )->then(function (int $langCount, array $quoteLangs, int $deRaw, int $enRaw, int $num, string $uiValue): void {
            $store = new InMemoryStore();
            $langIds = [];
            for ($i = 0; $i < $langCount; $i++) {
                $id = $store->allocateLanguageId();
                $store->languages[$id] = ['id' => $id, 'name' => 'L' . $i, 'nameNormalized' => 'l' . $i];
                $langIds[$i] = $id;
            }

            // Reference stock: ascending quote id => language id.
            $stock = [];
            foreach ($quoteLangs as $k) {
                $languageId = $langIds[$k % $langCount];
                $quoteId = $store->allocateQuoteId();
                $store->quotes[$quoteId] = [
                    'id' => $quoteId,
                    'text' => 'Q' . $quoteId,
                    'sourceId' => InMemoryStore::UNKNOWN_SOURCE_ID,
                    'languageId' => $languageId,
                ];
                $stock[$quoteId] = $languageId;
            }

            // Mapping: -1 = no entry; a quote language maps to at most one UI language.
            $mapping = [];
            if ($deRaw >= 0) {
                $mapping['de'] = $langIds[$deRaw % $langCount];
            }
            if ($enRaw >= 0) {
                $enId = $langIds[$enRaw % $langCount];
                if (!in_array($enId, $mapping, true)) {
                    $mapping['en'] = $enId;
                }
            }
            $store->uiLanguageMappings = $mapping;

            $r = $num / 1_000_000;
            $rng = new class ($r) implements RandomNumberGenerator {
                public function __construct(private readonly float $value)
                {
                }

                public function next(): float
                {
                    return $this->value;
                }
            };

            $selector = new RandomQuoteSelector(
                new InMemoryQuoteRepository($store),
                $rng,
                new InMemoryUiLanguageMappingRepository($store)
            );

            // Reference model.
            $expectedId = null;
            foreach ([$uiValue, 'en'] as $key) {
                if (!isset($mapping[$key])) {
                    continue;
                }
                $sub = array_keys(array_filter($stock, static fn (int $l): bool => $l === $mapping[$key]));
                sort($sub);
                if ($sub !== []) {
                    $expectedId = $sub[(int) floor($r * count($sub))];
                    break;
                }
            }
            if ($expectedId === null && $stock !== []) {
                $all = array_keys($stock);
                sort($all);
                $expectedId = $all[(int) floor($r * count($all))];
            }

            $picked = $selector->pick(UiLanguage::from($uiValue));

            self::assertSame(
                $expectedId,
                $picked['id'] ?? null,
                sprintf(
                    'ui=%s r=%.6f mapping=%s stock=%s',
                    $uiValue,
                    $r,
                    json_encode($mapping),
                    json_encode($stock)
                )
            );
        });
    }
}
