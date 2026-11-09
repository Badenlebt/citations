import { isSupportedLocale, type Locale } from './locales'

/** localStorage key under which the chosen UI locale is persisted (Req 14.3). */
export const LOCALE_STORAGE_KEY = 'citations.ui-locale'

/**
 * Reads the persisted UI locale choice, if any. Returns `null` when no valid
 * choice is stored or when storage is unavailable (e.g. private mode, SSR).
 * Access is wrapped in try/catch because `localStorage` can throw.
 */
export function readStoredLocale(): Locale | null {
  try {
    const value = globalThis.localStorage?.getItem(LOCALE_STORAGE_KEY)
    return isSupportedLocale(value) ? value : null
  } catch {
    return null
  }
}

/** Persists the chosen UI locale so the selection sticks across visits. */
export function writeStoredLocale(locale: Locale): void {
  try {
    globalThis.localStorage?.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Persistence is best-effort; a failure must not break the UI.
  }
}
