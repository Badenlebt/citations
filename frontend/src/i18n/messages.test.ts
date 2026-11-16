import { describe, expect, it } from 'vitest'
import { messages } from './messages'

// Catalog completeness smoke/compliance check (Req 14.1): the English catalog
// must carry exactly the same (nested) keys as the canonical German one, with
// no missing and no extra keys. The MessageSchema type already enforces this at
// compile time, but this runtime assertion guards against a drift that a loose
// edit or a future refactor could introduce, and documents the guarantee in the
// test suite.

/** Collect every leaf path of a nested catalog as dot-joined strings. */
function keyPaths(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') {
    return [prefix]
  }
  return Object.keys(value as Record<string, unknown>)
    .sort()
    .flatMap((key) => {
      const next = prefix ? `${prefix}.${key}` : key
      return keyPaths((value as Record<string, unknown>)[key], next)
    })
}

/** Flatten a nested catalog into { 'a.b.c': value } entries. */
function flatten(value: Record<string, unknown>, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [key, child] of Object.entries(value)) {
    const next = prefix ? `${prefix}.${key}` : key
    if (child !== null && typeof child === 'object') {
      Object.assign(out, flatten(child as Record<string, unknown>, next))
    } else {
      out[next] = child as string
    }
  }
  return out
}

describe('i18n message catalogs', () => {
  it('ships both the German and English catalogs', () => {
    expect(Object.keys(messages).sort()).toEqual(['de', 'en'])
  })

  it('has an English catalog with exactly the same keys as the German one', () => {
    const deKeys = keyPaths(messages.de)
    const enKeys = keyPaths(messages.en)

    const missingInEn = deKeys.filter((key) => !enKeys.includes(key))
    const extraInEn = enKeys.filter((key) => !deKeys.includes(key))

    expect(missingInEn, 'keys present in de but missing in en').toEqual([])
    expect(extraInEn, 'keys present in en but absent in de').toEqual([])
    expect(enKeys).toEqual(deKeys)
  })

  it('maps every key to a non-empty string in both catalogs', () => {
    for (const locale of ['de', 'en'] as const) {
      const flat = flatten(messages[locale])
      for (const [key, text] of Object.entries(flat)) {
        expect(typeof text, `${locale}.${key} must be a string`).toBe('string')
        expect(text.length, `${locale}.${key} must not be empty`).toBeGreaterThan(0)
      }
    }
  })

  // Req 6.1, 9.12: keys added for the random-quote homepage and UI language mapping.
  it('provides the new homepage and language-mapping keys, non-empty, in de and en', () => {
    const required = [
      'home.heading',
      'home.reload',
      'home.empty',
      'home.error',
      'home.searchLink',
      'nav.home',
      'admin.tabMappings',
      'admin.languageMappingsHeading',
      'admin.languageMappingsHint',
      'admin.languageMappingsNone',
      'admin.languageMappingsUiDe',
      'admin.languageMappingsUiEn',
      'admin.languageMappingsSaved',
      'errors.INVALID_UI_LANGUAGE',
      'errors.LANGUAGE_ALREADY_MAPPED',
    ]
    for (const locale of ['de', 'en'] as const) {
      const flat = flatten(messages[locale])
      for (const key of required) {
        expect(flat[key], `${locale}.${key} must exist`).toBeTypeOf('string')
        expect(flat[key].trim().length, `${locale}.${key} must not be empty`).toBeGreaterThan(0)
      }
    }
  })
})
