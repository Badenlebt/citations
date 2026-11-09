// Supported UI locales (Req 14.1). The UI locale is kept deliberately separate
// from the language assigned to individual quotes: switching the UI language
// must never change the language of displayed quote data (Req 14.4).

/** The two UI locales the frontend provides texts for. */
export const SUPPORTED_LOCALES = ['de', 'en'] as const

export type Locale = (typeof SUPPORTED_LOCALES)[number]

/**
 * Fallback UI locale used when the browser does not prefer any provided
 * locale, or when no stored choice exists and preferences cannot be read
 * (Req 14.3).
 */
export const DEFAULT_LOCALE: Locale = 'de'

const LOCALE_SET = new Set<string>(SUPPORTED_LOCALES)

/** Narrows an arbitrary value to a supported `Locale`, if it is one. */
export function isSupportedLocale(value: unknown): value is Locale {
  return typeof value === 'string' && LOCALE_SET.has(value)
}
