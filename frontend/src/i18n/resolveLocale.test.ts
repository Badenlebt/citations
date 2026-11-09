import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  resolveLocaleFromLanguages,
  readBrowserLanguages,
  resolveBrowserLocale,
} from './resolveLocale'
import { DEFAULT_LOCALE } from './locales'

// Browser default with German fallback (Req 14.3). These are example tests of
// the pure resolver driven by fabricated browser language lists, so the
// mapping can be verified without touching the real navigator.
describe('resolveLocaleFromLanguages', () => {
  it('picks the first preferred language that is a provided locale', () => {
    expect(resolveLocaleFromLanguages(['en-US', 'de'])).toBe('en')
  })

  it('reduces a regional tag to its primary subtag', () => {
    expect(resolveLocaleFromLanguages(['de-AT'])).toBe('de')
  })

  it('falls back to German when no preferred language is provided', () => {
    expect(resolveLocaleFromLanguages(['fr', 'es'])).toBe('de')
  })

  it('respects preference order, choosing German when it comes first', () => {
    expect(resolveLocaleFromLanguages(['de-DE', 'en-GB'])).toBe('de')
    expect(resolveLocaleFromLanguages(['en-GB', 'de-DE'])).toBe('en')
  })

  it('falls back to German for an empty list', () => {
    expect(resolveLocaleFromLanguages([])).toBe(DEFAULT_LOCALE)
    expect(resolveLocaleFromLanguages([])).toBe('de')
  })

  it('falls back to German for null or undefined input', () => {
    expect(resolveLocaleFromLanguages(null)).toBe('de')
    expect(resolveLocaleFromLanguages(undefined)).toBe('de')
  })

  it('is case-insensitive about the language tag', () => {
    expect(resolveLocaleFromLanguages(['EN-us'])).toBe('en')
    expect(resolveLocaleFromLanguages(['DE'])).toBe('de')
  })

  it('skips non-string entries without throwing', () => {
    const languages = [undefined, 42, 'en'] as unknown as string[]
    expect(resolveLocaleFromLanguages(languages)).toBe('en')
  })
})

describe('readBrowserLanguages', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('prefers the ordered navigator.languages list when populated', () => {
    vi.stubGlobal('navigator', { languages: ['en-US', 'de'], language: 'en-US' })
    expect(readBrowserLanguages()).toEqual(['en-US', 'de'])
  })

  it('falls back to the single navigator.language when the list is empty', () => {
    vi.stubGlobal('navigator', { languages: [], language: 'de-DE' })
    expect(readBrowserLanguages()).toEqual(['de-DE'])
  })

  it('returns an empty list when the navigator exposes no languages', () => {
    vi.stubGlobal('navigator', { languages: [], language: '' })
    expect(readBrowserLanguages()).toEqual([])
  })
})

describe('resolveBrowserLocale', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('maps the live browser preferences to a provided locale', () => {
    vi.stubGlobal('navigator', { languages: ['en-GB'], language: 'en-GB' })
    expect(resolveBrowserLocale()).toBe('en')
  })

  it('falls back to German when the browser prefers an unprovided language', () => {
    vi.stubGlobal('navigator', { languages: ['fr-FR'], language: 'fr-FR' })
    expect(resolveBrowserLocale()).toBe('de')
  })
})
