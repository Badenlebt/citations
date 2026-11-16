<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\LanguageMappingService;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 5: Zuordnungs-Invarianten und Modelltreue unter beliebigen Operationsfolgen
 *
 * For every sequence of assign(ui, languageId) (valid and invalid arguments)
 * and remove(ui) on LanguageMappingService with in-memory repositories, after
 * every step: (a) at most one entry per UI language, (b) no quote language is
 * mapped to several UI languages, (c) the state equals a simple reference
 * model (assign replaces, remove deletes - also repeatedly, an otherwise
 * mapped language yields LANGUAGE_ALREADY_MAPPED), (d) after assign findAll
 * returns the id, after remove no entry, (e) a rejected call leaves the state
 * identical.
 *
 * Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 9.7
 */
final class LanguageMappingModelPropertyTest extends PropertyTestCase
{
    public function testMappingInvariantsAndModelFidelity(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\vector(
                25,
                Generator\tuple(
                    Generator\choose(0, 1),  // 0 = assign, 1 = remove
                    Generator\choose(0, 2),  // ui: 0 = de, 1 = en, 2 = invalid (null)
                    Generator\choose(0, 7)   // language: 0..2 valid, others invalid
                )
            )
        )->then(function (array $ops): void {
            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $mappings = new InMemoryUiLanguageMappingRepository($store);
            $ids = [];
            foreach (['Deutsch', 'English', 'Latein'] as $name) {
                $ids[] = $languages->insert($name, TextNormalizer::normalize($name));
            }
            $service = new LanguageMappingService($mappings, $languages);
            $invalidLanguages = [null, 0, -1, 99999, 123456];

            /** @var array<string,int> $model */
            $model = [];

            foreach ($ops as [$kind, $uiIdx, $langIdx]) {
                $ui = match ($uiIdx) {
                    0 => UiLanguage::DE,
                    1 => UiLanguage::EN,
                    default => null,
                };
                $before = $mappings->findAll();

                if ($kind === 0) {
                    $languageId = $langIdx < 3 ? $ids[$langIdx] : $invalidLanguages[$langIdx - 3];
                    $result = $service->assign($ui, $languageId);

                    if ($ui === null) {
                        $expected = ErrorCode::INVALID_UI_LANGUAGE;
                    } elseif ($languageId === null || !in_array($languageId, $ids, true)) {
                        $expected = ErrorCode::INVALID_LANGUAGE;
                    } else {
                        $owner = array_search($languageId, $model, true);
                        $expected = ($owner !== false && $owner !== $ui->value)
                            ? ErrorCode::LANGUAGE_ALREADY_MAPPED
                            : null;
                    }

                    if ($expected === null) {
                        self::assertTrue($result->isSuccess);
                        $model[$ui->value] = $languageId;
                        self::assertSame($languageId, $mappings->findAll()[$ui->value] ?? null); // (d)
                    } else {
                        self::assertFalse($result->isSuccess);
                        self::assertSame($expected, $result->error);
                        self::assertSame($before, $mappings->findAll()); // (e)
                    }
                } else {
                    $result = $service->remove($ui);
                    if ($ui === null) {
                        self::assertFalse($result->isSuccess);
                        self::assertSame(ErrorCode::INVALID_UI_LANGUAGE, $result->error);
                        self::assertSame($before, $mappings->findAll()); // (e)
                    } else {
                        self::assertTrue($result->isSuccess);
                        unset($model[$ui->value]);
                        self::assertArrayNotHasKey($ui->value, $mappings->findAll()); // (d)
                    }
                }

                $actual = $mappings->findAll();
                // (a) only valid UI languages appear (array keys are unique by construction)
                self::assertSame([], array_diff(array_keys($actual), ['de', 'en']));
                // (b) no quote language mapped twice
                self::assertSame(count($actual), count(array_unique($actual)));
                // (c) reference model
                $expectedModel = $model;
                ksort($expectedModel);
                self::assertSame($expectedModel, $actual);
            }
        });
    }
}
