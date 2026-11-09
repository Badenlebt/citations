import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n, resolveInitialLocale } from './useI18n'
import { LOCALE_STORAGE_KEY } from './storage'

// In-memory localStorage stub so stored-choice precedence can be exercised
// without a real browser (Req 14.3).
function stubStorage(initial: Record<string, string> = {}): void {
  const store = new Map<string, string>(Object.entries(initial))
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => {
      store.set(key, value)
    },
    removeItem: (key: string) => {
      store.delete(key)
    },
    clear: () => {
      store.clear()
    },
  })
}

describe('resolveInitialLocale', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('prefers a previously stored choice over browser preferences', () => {
    // Stored 'en' must win even though the browser prefers German.
    stubStorage({ [LOCALE_STORAGE_KEY]: 'en' })
    vi.stubGlobal('navigator', { languages: ['de-DE'], language: 'de-DE' })

    expect(resolveInitialLocale()).toBe('en')
  })

  it('uses the browser preference when no choice is stored', () => {
    stubStorage({})
    vi.stubGlobal('navigator', { languages: ['en-US', 'de'], language: 'en-US' })

    expect(resolveInitialLocale()).toBe('en')
  })

  it('falls back to German when neither a stored choice nor a provided browser language exists', () => {
    stubStorage({})
    vi.stubGlobal('navigator', { languages: ['fr-FR'], language: 'fr-FR' })

    expect(resolveInitialLocale()).toBe('de')
  })

  it('ignores an invalid stored value and falls through to the browser', () => {
    stubStorage({ [LOCALE_STORAGE_KEY]: 'xx' })
    vi.stubGlobal('navigator', { languages: ['en-GB'], language: 'en-GB' })

    expect(resolveInitialLocale()).toBe('en')
  })
})

describe('createI18n setLocale', () => {
  beforeEach(() => {
    stubStorage({})
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('switches the active catalog and persists the choice', () => {
    const i18n = createI18n('de')
    expect(i18n.t('app.title')).toBe('Zitate')

    i18n.setLocale('en')

    expect(i18n.locale.value).toBe('en')
    expect(i18n.t('app.title')).toBe('Quotes')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en')
  })
})
