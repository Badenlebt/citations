<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\LanguageRepositoryInterface;
use Citations\Repository\UiLanguageMappingRepositoryInterface;

/**
 * Assign / remove the 1:1 mapping UI language => quote language
 * (random-quote-homepage, Requirements 9.4-9.7; design "LanguageMappingService").
 *
 * Check order for assign(): INVALID_UI_LANGUAGE, INVALID_LANGUAGE,
 * LANGUAGE_ALREADY_MAPPED. Re-assigning the same pair is an idempotent
 * success; remove() is idempotent. On any error the stock stays unchanged.
 */
final class LanguageMappingService
{
    public function __construct(
        private readonly UiLanguageMappingRepositoryInterface $mappings,
        private readonly LanguageRepositoryInterface $languages,
    ) {
    }

    public function assign(?UiLanguage $ui, int|null $languageId): LanguageMappingResult
    {
        if ($ui === null) {
            return LanguageMappingResult::failure(ErrorCode::INVALID_UI_LANGUAGE);
        }
        if ($languageId === null || $languageId < 1 || !$this->languages->exists($languageId)) {
            return LanguageMappingResult::failure(ErrorCode::INVALID_LANGUAGE);
        }

        $owner = $this->mappings->findUiLanguageByLanguageId($languageId);
        if ($owner === $ui->value) {
            return LanguageMappingResult::success($ui, $languageId);
        }
        if ($owner !== null) {
            return LanguageMappingResult::failure(ErrorCode::LANGUAGE_ALREADY_MAPPED);
        }
        if (!$this->mappings->assign($ui->value, $languageId)) {
            return LanguageMappingResult::failure(ErrorCode::LANGUAGE_ALREADY_MAPPED);
        }

        return LanguageMappingResult::success($ui, $languageId);
    }

    public function remove(?UiLanguage $ui): LanguageMappingResult
    {
        if ($ui === null) {
            return LanguageMappingResult::failure(ErrorCode::INVALID_UI_LANGUAGE);
        }
        $this->mappings->remove($ui->value);

        return LanguageMappingResult::success($ui, null);
    }
}
