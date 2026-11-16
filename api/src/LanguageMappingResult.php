<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable outcome of {@see LanguageMappingService::assign()} / remove()
 * (random-quote-homepage, Requirements 9.4-9.7).
 *
 * On success it carries the UI language and the resulting quote language id
 * (null after a removal); on failure exactly one {@see ErrorCode}.
 */
final class LanguageMappingResult
{
    private function __construct(
        public readonly bool $isSuccess,
        public readonly ?UiLanguage $uiLanguage,
        public readonly ?int $languageId,
        public readonly ?ErrorCode $error,
    ) {
    }

    public static function success(UiLanguage $uiLanguage, ?int $languageId): self
    {
        return new self(true, $uiLanguage, $languageId, null);
    }

    public static function failure(ErrorCode $error): self
    {
        return new self(false, null, null, $error);
    }
}
