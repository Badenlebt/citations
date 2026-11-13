import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import { messages } from '@/i18n/messages'
import { SUPPORTED_LOCALES, type Locale } from '@/i18n'

// Property 10 (Req 8.3): das Redesign darf keine i18n-Schluessel entfernen oder
// umbenennen. Jeder voll-qualifizierte Schluessel (z. B. `admin.tabQuotes`) muss
// nach dem Redesign in JEDEM Sprachkatalog weiterhin existieren und auf einen
// nicht-leeren String verweisen. Wir flachen die verschachtelten Kataloge zu
// Schluesselpfaden ab und pruefen sie gegen eine im Test hinterlegte
// Referenzmenge der bekannten Schluessel.

/** Flache einen verschachtelten Katalog zu voll-qualifizierten Pfaden ab. */
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

/** Sortierte Menge aller voll-qualifizierten Schluessel eines Katalogs. */
function keyPaths(catalog: Record<string, unknown>): string[] {
  return Object.keys(flatten(catalog)).sort()
}

// Referenzmenge der bekannten Schluessel, abgeleitet aus dem aktuellen
// kanonischen (deutschen) Katalog. Diese Liste ist die explizite "vor dem
// Redesign"-Erwartung: keiner dieser Schluessel darf verschwinden oder
// umbenannt werden.
const REFERENCE_KEYS: readonly string[] = [
  'admin.actions',
  'admin.confirmDelete',
  'admin.create',
  'admin.deleteCancel',
  'admin.deleteCancelHint',
  'admin.deleteConflictHeading',
  'admin.deleteConflictMessage',
  'admin.deleteReassign',
  'admin.deleteReassignHint',
  'admin.deleting',
  'admin.emptyLanguages',
  'admin.emptyQuotes',
  'admin.emptySources',
  'admin.heading',
  'admin.languageName',
  'admin.loadFailed',
  'admin.loggingIn',
  'admin.login',
  'admin.loginHeading',
  'admin.logout',
  'admin.newLanguage',
  'admin.newQuote',
  'admin.newSource',
  'admin.noSourceSelected',
  'admin.password',
  'admin.quoteLanguage',
  'admin.quoteSource',
  'admin.quoteText',
  'admin.saved',
  'admin.saving',
  'admin.signedInAs',
  'admin.sourceBirthDate',
  'admin.sourceDeathDate',
  'admin.sourceDescription',
  'admin.sourceLinkUrl',
  'admin.sourceName',
  'admin.tabLanguages',
  'admin.tabQuotes',
  'admin.tabSources',
  'admin.unknownSource',
  'admin.update',
  'admin.username',
  'app.title',
  'common.cancel',
  'common.delete',
  'common.edit',
  'common.error',
  'common.loading',
  'common.save',
  'errors.INPUT_TOO_LONG',
  'errors.INTERNAL_ERROR',
  'errors.INVALID_CREDENTIALS',
  'errors.INVALID_LANGUAGE',
  'errors.INVALID_LINK_URL',
  'errors.INVALID_SOURCE',
  'errors.LANGUAGE_EXISTS',
  'errors.MISSING_CREDENTIALS',
  'errors.MISSING_LANGUAGE_NAME',
  'errors.MISSING_NAME',
  'errors.MISSING_TEXT',
  'errors.NOT_FOUND',
  'errors.RATE_LIMITED',
  'errors.UNAUTHORIZED',
  'errors.UNKNOWN_SOURCE_RESERVED',
  'errors.UNKNOWN_SOURCE_UNDELETABLE',
  'errors.unknown',
  'language.de',
  'language.en',
  'language.label',
  'nav.admin',
  'nav.search',
  'notFound.backToSearch',
  'notFound.heading',
  'notFound.message',
  'quote.backToSearch',
  'quote.externalLink',
  'quote.heading',
  'quote.language',
  'quote.notFound',
  'quote.source',
  'search.clearSourceFilter',
  'search.emptyQuery',
  'search.filterAllLanguages',
  'search.filterLanguages',
  'search.heading',
  'search.inputLabel',
  'search.inputPlaceholder',
  'search.nextPage',
  'search.noResults',
  'search.openQuote',
  'search.pageInfo',
  'search.previousPage',
  'search.randomQuote',
  'search.resultsCount',
  'search.sourceFilterActive',
  'search.submit',
  'source.backToSearch',
  'source.birthDate',
  'source.deathDate',
  'source.description',
  'source.heading',
  'source.link',
  'source.notFound',
  'source.quotes',
  'source.viewQuotes',
].slice().sort()

describe('i18n-Schluessel bleiben erhalten (Property 10)', () => {
  const locales = SUPPORTED_LOCALES as readonly Locale[]
  const flatByLocale: Record<Locale, Record<string, string>> = Object.fromEntries(
    locales.map((locale) => [locale, flatten(messages[locale])]),
  ) as Record<Locale, Record<string, string>>

  it('deckt mindestens de und en ab', () => {
    expect(locales).toEqual(expect.arrayContaining(['de', 'en']))
  })

  it('haben in jedem Sprachkatalog exakt dieselbe Schluesselmenge', () => {
    const reference = keyPaths(messages[locales[0]])
    for (const locale of locales) {
      expect(keyPaths(messages[locale]), `Schluesselmenge von ${locale}`).toEqual(reference)
    }
  })

  it('enthalten jeden Schluessel der Referenzmenge (keine Entfernung/Umbenennung)', () => {
    for (const locale of locales) {
      const present = keyPaths(messages[locale])
      const missing = REFERENCE_KEYS.filter((key) => !present.includes(key))
      expect(missing, `in ${locale} fehlende Referenz-Schluessel`).toEqual([])
    }
    // Keine Drift gegenueber der Referenzmenge (auch keine unbeabsichtigten Extras
    // im kanonischen Katalog, die die Referenz nicht kennt).
    expect(keyPaths(messages[locales[0]])).toEqual(REFERENCE_KEYS)
  })

  // Feature: ui-redesign, Property 10: i18n-Schluessel bleiben erhalten
  // Fuer jeden bekannten Schluessel gilt: er existiert in JEDEM Sprachkatalog und
  // verweist dort auf einen nicht-leeren String. Der Generator zieht aus der
  // Schluesselmenge; damit wird die Invariante ueber die gesamte Schluesselmenge
  // in vielen Laeufen gepraeft.
  it('existieren in allen Locales als nicht-leerer String', () => {
    fc.assert(
      fc.property(fc.constantFrom(...REFERENCE_KEYS), (key) => {
        for (const locale of locales) {
          const value = flatByLocale[locale][key]
          expect(typeof value, `${locale}: ${key} muss ein String sein`).toBe('string')
          expect(value.length, `${locale}: ${key} darf nicht leer sein`).toBeGreaterThan(0)
        }
      }),
      { numRuns: 100 },
    )
  })
})
