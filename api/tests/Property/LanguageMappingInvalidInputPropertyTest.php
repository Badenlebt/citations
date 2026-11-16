<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\LanguageMappingService;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 6: Ungültige Eingaben werden abgelehnt ohne Zustandsänderung
 *
 * For every unsupported UI language (any string except de/en, i.e.
 * `UiLanguage::tryFromInput()` is null) and every missing or erroneous quote
 * language id (null, <= 0, not existing), `LanguageMappingService::assign()`
 * returns INVALID_UI_LANGUAGE resp. INVALID_LANGUAGE and leaves the mapping
 * stock (empty or pre-populated) unchanged.
 *
 * Validates: Requirements 9.6
 */
final class LanguageMappingInvalidInputPropertyTest extends PropertyTestCase
{
    public function testInvalidUiLanguageIsRejectedWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\string(),
            Generator\choose(0, 3),   // pre-assigned "de" (0 = none)
            Generator\choose(0, 3),   // pre-assigned "en" (0 = none)
            Generator\choose(-5, 10)  // arbitrary language id
        )->then(function (string $raw, int $deMap, int $enMap, int $languageId): void {
            if (UiLanguage::tryFromInput($raw) !== null) {
                $raw .= 'x';
            }
            self::assertNull(UiLanguage::tryFromInput($raw));

            [$service, $mappings, $ids] = $this->setUp3($deMap, $enMap);
            $before = $mappings->findAll();

            $result = $service->assign(UiLanguage::tryFromInput($raw), $languageId);

            self::assertFalse($result->isSuccess);
            self::assertSame(ErrorCode::INVALID_UI_LANGUAGE, $result->error);
            self::assertSame($before, $mappings->findAll());
        });
    }

    public function testInvalidLanguageIdIsRejectedWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, 3),
            Generator\choose(0, 3),
            Generator\choose(0, 1),   // which supported UI language
            Generator\choose(0, 3),   // kind of invalid id
            Generator\choose(0, 1000)
        )->then(function (int $deMap, int $enMap, int $uiPick, int $kind, int $n): void {
            [$service, $mappings, $ids] = $this->setUp3($deMap, $enMap);
            $before = $mappings->findAll();

            $languageId = match ($kind) {
                0 => null,
                1 => -$n,
                2 => 0,
                default => max($ids) + 1 + $n, // not existing
            };
            $ui = UiLanguage::tryFromInput($uiPick === 0 ? 'de' : 'en');
            self::assertNotNull($ui);

            $result = $service->assign($ui, $languageId);

            self::assertFalse($result->isSuccess);
            self::assertSame(ErrorCode::INVALID_LANGUAGE, $result->error);
            self::assertSame($before, $mappings->findAll());
        });
    }

    /**
     * @return array{0: LanguageMappingService, 1: InMemoryUiLanguageMappingRepository, 2: list<int>}
     */
    private function setUp3(int $deMap, int $enMap): array
    {
        $store = new InMemoryStore();
        $languages = new InMemoryLanguageRepository($store);
        $mappings = new InMemoryUiLanguageMappingRepository($store);
        $ids = [
            $languages->insert('Deutsch', 'deutsch'),
            $languages->insert('English', 'english'),
            $languages->insert('Latein', 'latein'),
        ];
        if ($deMap > 0) {
            $mappings->assign('de', $ids[$deMap - 1]);
        }
        if ($enMap > 0) {
            $mappings->assign('en', $ids[$enMap - 1]);
        }

        return [new LanguageMappingService($mappings, $languages), $mappings, $ids];
    }
}
