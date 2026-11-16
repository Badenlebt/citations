import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import { createI18n, type MessageKey } from './useI18n'
import { messages } from './messages'
import { SUPPORTED_LOCALES, type Locale } from './locales'

// Feature: random-quote-homepage, Property 14: i18n-Rückfall auf die andere Sprache
// **Validates: Requirements 6.5**

type Group = Record<string, string | undefined>

const ALL_KEYS: MessageKey[] = Object.entries(messages.de).flatMap(
  ([section, group]) =>
    Object.keys(group as Group).map((entry) => `${section}.${entry}` as MessageKey),
)

function slot(locale: Locale, key: MessageKey): { group: Group; entry: string } {
  const [section, entry] = key.split('.') as [keyof typeof messages.de, string]
  return { group: messages[locale][section] as unknown as Group, entry }
}

/** Temporarily sets the texts of a key in both locales, then restores them. */
function withTexts(
  key: MessageKey,
  texts: Record<Locale, string | undefined>,
  run: () => void,
): void {
  const saved = new Map<Locale, string | undefined>()
  for (const locale of SUPPORTED_LOCALES) {
    const { group, entry } = slot(locale, key)
    saved.set(locale, group[entry])
    if (texts[locale] === undefined) {
      delete group[entry]
    } else {
      group[entry] = texts[locale]
    }
  }
  try {
    run()
  } finally {
    for (const locale of SUPPORTED_LOCALES) {
      const { group, entry } = slot(locale, key)
      const original = saved.get(locale)
      if (original === undefined) {
        delete group[entry]
      } else {
        group[entry] = original
      }
    }
  }
}

const keyArb = fc.constantFrom(...ALL_KEYS)
const localeArb = fc.constantFrom(...SUPPORTED_LOCALES)
const textArb = fc.string({ minLength: 1 }).filter((s) => s.trim() !== '')
const brokenArb = fc.constantFrom<string | undefined>(undefined, '')

function otherOf(locale: Locale): Locale {
  return SUPPORTED_LOCALES.find((l) => l !== locale) as Locale
}

describe('Property 14: i18n-Rückfall auf die andere Sprache', () => {
  it('liefert bei fehlendem/leerem Text die Übersetzung der anderen Sprache, nie den Schlüssel', () => {
    fc.assert(
      fc.property(keyArb, localeArb, brokenArb, textArb, (key, locale, broken, otherText) => {
        fc.pre(otherText !== key)
        const texts = { [locale]: broken, [otherOf(locale)]: otherText } as Record<
          Locale,
          string | undefined
        >
        withTexts(key, texts, () => {
          const result = createI18n(locale).t(key)
          expect(result).toBe(otherText)
          expect(result).not.toBe(key)
        })
      }),
      { numRuns: 100 },
    )
  })

  it('liefert vorhandene Übersetzungen der aktiven Sprache unverändert', () => {
    fc.assert(
      fc.property(
        keyArb,
        localeArb,
        textArb,
        fc.oneof(textArb, brokenArb),
        (key, locale, ownText, otherText) => {
          const texts = { [locale]: ownText, [otherOf(locale)]: otherText } as Record<
            Locale,
            string | undefined
          >
          withTexts(key, texts, () => {
            expect(createI18n(locale).t(key)).toBe(ownText)
          })
        },
      ),
      { numRuns: 100 },
    )
  })
})
