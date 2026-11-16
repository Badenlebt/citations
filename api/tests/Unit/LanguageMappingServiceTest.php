<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\LanguageMappingService;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use PHPUnit\Framework\TestCase;

/** Requirements 9.4-9.7 for {@see LanguageMappingService}. */
final class LanguageMappingServiceTest extends TestCase
{
    private InMemoryUiLanguageMappingRepository $mappings;
    private LanguageMappingService $service;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        $store = new InMemoryStore();
        $languages = new InMemoryLanguageRepository($store);
        $this->mappings = new InMemoryUiLanguageMappingRepository($store);
        $this->a = $languages->insert('Deutsch', TextNormalizer::normalize('Deutsch'));
        $this->b = $languages->insert('English', TextNormalizer::normalize('English'));
        $this->service = new LanguageMappingService($this->mappings, $languages);
    }

    public function testAssignReplaceAndIdempotent(): void
    {
        self::assertTrue($this->service->assign(UiLanguage::DE, $this->a)->isSuccess);
        self::assertTrue($this->service->assign(UiLanguage::DE, $this->a)->isSuccess);
        self::assertTrue($this->service->assign(UiLanguage::DE, $this->b)->isSuccess);
        self::assertSame(['de' => $this->b], $this->mappings->findAll());
    }

    public function testErrorsLeaveStateUnchanged(): void
    {
        $this->service->assign(UiLanguage::DE, $this->a);
        $before = $this->mappings->findAll();

        self::assertSame(ErrorCode::INVALID_UI_LANGUAGE, $this->service->assign(null, 999)->error);
        self::assertSame(ErrorCode::INVALID_LANGUAGE, $this->service->assign(UiLanguage::EN, 999)->error);
        self::assertSame(ErrorCode::INVALID_LANGUAGE, $this->service->assign(UiLanguage::EN, null)->error);
        self::assertSame(ErrorCode::LANGUAGE_ALREADY_MAPPED, $this->service->assign(UiLanguage::EN, $this->a)->error);
        self::assertSame(ErrorCode::INVALID_UI_LANGUAGE, $this->service->remove(null)->error);
        self::assertSame($before, $this->mappings->findAll());
    }

    public function testRemoveIsIdempotent(): void
    {
        $this->service->assign(UiLanguage::DE, $this->a);
        self::assertTrue($this->service->remove(UiLanguage::DE)->isSuccess);
        self::assertTrue($this->service->remove(UiLanguage::DE)->isSuccess);
        self::assertSame([], $this->mappings->findAll());
    }
}
