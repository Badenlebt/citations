import {
  DEFAULT_LOCALE,
  SUPPORTED_LOCALES,
  isSupportedLocale,
  type Locale,
} from './locales'

/**
 * Maps a browser's ordered list of preferred language tags to a provided UI
 * locale (Req 14.3).
 *
 * The list mirrors `navigator.languages` (most-preferred first). Each tag may
 * be a full BCP-47 tag such as `de-AT` or `en-US`; only the primary subtag is
 * considered. The first tag whose primary subtag is a provided locale wins. If
 * none of the preferred languages is a provided locale, the function falls back
 * to German (`DEFAULT_LOCALE`).
 *
 * This is a pure function (no DOM access) so the locale-resolution logic can be
 * unit-tested with fabricated browser preferences.
 *
 * @param preferredLanguages ordered browser language tags, most preferred first.
 * @returns the resolved provided locale, or the German fallback.
 */
export function resolveLocaleFromLanguages(
  preferredLanguages: readonly string[] | null | undefined,
): Locale {
  if (!preferredLanguages) {
    return DEFAULT_LOCALE
  }

  for (const tag of preferredLanguages) {
    if (typeof tag !== 'string') {
      continue
    }
    // Reduce a BCP-47 tag (e.g. "en-GB") to its primary subtag ("en").
    const primary = tag.split('-', 1)[0]?.trim().toLowerCase()
    if (isSupportedLocale(primary)) {
      return primary
    }
  }

  return DEFAULT_LOCALE
}

/**
 * Reads the browser's preferred languages in a SSR/test-safe way, preferring
 * the ordered `navigator.languages` list and falling back to the single
 * `navigator.language`. Returns an empty list when no navigator is available.
 */
export function readBrowserLanguages(): readonly string[] {
  if (typeof navigator === 'undefined') {
    return []
  }
  if (Array.isArray(navigator.languages) && navigator.languages.length > 0) {
    return navigator.languages
  }
  return navigator.language ? [navigator.language] : []
}

/** Convenience resolver using the live browser preferences (Req 14.3). */
export function resolveBrowserLocale(): Locale {
  return resolveLocaleFromLanguages(readBrowserLanguages())
}

export { SUPPORTED_LOCALES, DEFAULT_LOCALE }
