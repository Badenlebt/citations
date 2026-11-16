<?php

declare(strict_types=1);

namespace Citations;

/**
 * Supported UI languages. Single source of truth for the values accepted by
 * `uiLanguage` parameters and language mappings.
 */
enum UiLanguage: string
{
    case DE = 'de';
    case EN = 'en';

    /**
     * Parse untrusted input: only exactly "de" or "en" after trimming are
     * accepted, everything else (including non-strings) yields null.
     */
    public static function tryFromInput(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        return self::tryFrom(trim($value));
    }
}
