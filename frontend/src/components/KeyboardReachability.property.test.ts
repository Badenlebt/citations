import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import type { App } from 'vue'
import fc from 'fast-check'
import AppHeader from './AppHeader.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import ScrollToTop from './ScrollToTop.vue'
import AdminLogin from './admin/AdminLogin.vue'
import { routes } from '@/router'
import { createI18n, installI18n, type I18n } from '@/i18n'

// =============================================================================
// Task 8.4 — Property-Test: Tastatur-Erreichbarkeit aller interaktiven Elemente
//
// Feature: ui-redesign, Property 12: Tastatur-Erreichbarkeit aller interaktiven Elemente
//
// Design (design.md, Property 12): *Fuer jede* View gilt: die Menge der per
// Tabulator erreichbaren Elemente ist nicht leer und umfasst alle interaktiven
// Elemente der View in einer der visuellen Anordnung entsprechenden
// Reihenfolge; ist ein interaktives Element nicht erreichbar, schlaegt der
// Barrierefreiheitstest fehl.
//
// Validates: Requirements 9.3, 9.6
// -----------------------------------------------------------------------------
//
// jsdom-Einschraenkung (dokumentiert, da sie die Testmethodik bestimmt):
//
// Vitest laeuft hier gegen jsdom. jsdom implementiert KEINE Layout-/Fokus-
// Engine: Es rendert keine echte Tab-Reihenfolge, fuehrt keine sequenzielle
// Fokus-Navigation aus und berechnet keine visuelle Anordnung. Ein echtes
// Tab-Traversal (wiederholtes Tab-Druecken und Beobachten von
// document.activeElement in geometrischer Reihenfolge) ist daher nicht
// aussagekraeftig.
//
// Gewaehlter Ansatz: Tabbbarkeit ist in HTML durch Elementtyp + tabindex
// bestimmt und damit statisch aus dem gemounteten DOM ablesbar. Ein nativ
// fokussierbares Bedienelement (a[href], button, input, select, textarea) ist
// genau dann der Tab-Reihenfolge ENTZOGEN, wenn es ein negatives tabindex
// (z. B. tabindex="-1") traegt. Positive/null tabindex-Werte halten es in der
// Reihenfolge; `disabled` ist ein erlaubter, separater Zustand (Req 6.5) und
// macht ein Element nicht zu einer Barrierefreiheitsverletzung. Wir mounten
// daher repraesentative Layout-/View-Komponenten mit dem bestehenden Mount-/
// i18n-/Router-Muster (vgl. AppHeader.test.ts / LanguageSwitcher.test.ts;
// localStorage-Stub), sammeln je Komponente alle interaktiven Elemente und
// pruefen fuer jedes, dass es tabbbar ist (kein negativer tabindex; nativ
// fokussierbar). Zusaetzlich wird gefordert, dass die Menge der tabbbaren
// Elemente je gemounteter Komponente NICHT LEER ist (Property 12).
// -----------------------------------------------------------------------------

// In-memory localStorage stub, damit LanguageSwitchers setLocale nie wirft.
function stubStorage(): void {
  const store = new Map<string, string>()
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

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

// Selektor-Menge der interaktiven Elemente (gemaess Task-Vorgabe): nativ
// fokussierbare Bedienelemente plus explizit tabbbar gemachte Elemente
// ([tabindex] ausser dem der Reihenfolge entzogenen -1).
const INTERACTIVE_SELECTOR =
  'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'

// Nativ fokussierbare Bedienelement-Tags (ohne explizites tabindex bereits in
// der Tab-Reihenfolge, solange kein negativer tabindex sie entzieht).
const NATIVELY_FOCUSABLE = new Set(['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA'])

// Sammelt alle interaktiven Elemente einer gemounteten Komponente. Wichtig:
// `querySelectorAll` durchsucht nur Nachfahren, nicht das Wurzelelement selbst.
// Ist die Komponentenwurzel selbst interaktiv (z. B. ScrollToTop rendert einen
// <button> als Root), muss sie zusaetzlich beruecksichtigt werden — sonst
// bliebe genau dieses Bedienelement ungeprueft.
function collectInteractive(root: Element): Element[] {
  const found = Array.from(root.querySelectorAll(INTERACTIVE_SELECTOR))
  if (root.matches(INTERACTIVE_SELECTOR)) {
    found.unshift(root)
  }
  return found
}

// Liefert true, wenn ein Element der Tab-Reihenfolge ENTZOGEN ist, d. h. ein
// negatives tabindex traegt. (disabled ist bewusst ausgenommen — ein erlaubter,
// separater Zustand nach Req 6.5, keine Barrierefreiheitsverletzung.)
function hasNegativeTabindex(el: Element): boolean {
  const raw = el.getAttribute('tabindex')
  if (raw === null) return false
  const value = Number.parseInt(raw, 10)
  return Number.isFinite(value) && value < 0
}

// Ein interaktives Element gilt als tabbbar, wenn es (a) kein negatives
// tabindex traegt UND (b) entweder nativ fokussierbar ist oder ein nicht-
// negatives tabindex explizit setzt.
function isTabbable(el: Element): boolean {
  if (hasNegativeTabindex(el)) return false
  if (NATIVELY_FOCUSABLE.has(el.tagName)) return true
  const raw = el.getAttribute('tabindex')
  if (raw === null) return false
  const value = Number.parseInt(raw, 10)
  return Number.isFinite(value) && value >= 0
}

interface MountCase {
  readonly name: string
  readonly mount: (i18n: I18n, router: Router) => Promise<VueWrapper>
}

// i18n-Plugin-Installer passend zum bestehenden Muster.
function i18nPlugin(i18n: I18n) {
  return (app: App) => installI18n(app, i18n)
}

// Repraesentative Layout-/View-Komponenten mit interaktiven Elementen.
//  - AppHeader: Navigation (router-links), Hamburger-<button>, LanguageSwitcher.
//  - LanguageSwitcher: <select> (nativ fokussierbar).
//  - ScrollToTop: nativer <button> (v-show -> im DOM vorhanden).
//  - AdminLogin: zwei <input> und ein Submit-<button>.
const CASES: readonly MountCase[] = [
  {
    name: 'AppHeader',
    mount: async (i18n, router) => {
      await router.push('/')
      await router.isReady()
      const wrapper = mount(AppHeader, {
        global: { plugins: [router, i18nPlugin(i18n)] },
      })
      await wrapper.vm.$nextTick()
      return wrapper
    },
  },
  {
    name: 'LanguageSwitcher',
    mount: async (i18n) => {
      const wrapper = mount(LanguageSwitcher, {
        global: { plugins: [i18nPlugin(i18n)] },
      })
      await wrapper.vm.$nextTick()
      return wrapper
    },
  },
  {
    name: 'ScrollToTop',
    mount: async () => {
      // ScrollToTop nutzt kein i18n/Router; v-show haelt den <button> im DOM.
      const wrapper = mount(ScrollToTop)
      await wrapper.vm.$nextTick()
      return wrapper
    },
  },
  {
    name: 'AdminLogin',
    mount: async (i18n) => {
      const wrapper = mount(AdminLogin, {
        global: { plugins: [i18nPlugin(i18n)] },
      })
      await wrapper.vm.$nextTick()
      return wrapper
    },
  },
]

describe('Tastatur-Erreichbarkeit interaktiver Elemente (Task 8.4)', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.documentElement.removeAttribute('lang')
  })

  // Vorab-Beleg der Methodik: jede gemountete Komponente traegt ueberhaupt
  // interaktive Elemente (sonst waere die Property trivial erfuellbar).
  it('mountet Komponenten, die interaktive Elemente enthalten', async () => {
    const i18n = createI18n('de')
    const router = makeRouter()
    for (const testCase of CASES) {
      const wrapper = await testCase.mount(i18n, router)
      try {
        const interactive = collectInteractive(wrapper.element)
        expect(
          interactive.length,
          `${testCase.name} enthaelt keine interaktiven Elemente`,
        ).toBeGreaterThan(0)
      } finally {
        wrapper.unmount()
      }
    }
  })

  // Feature: ui-redesign, Property 12: Tastatur-Erreichbarkeit aller interaktiven Elemente
  it('haelt in jeder gemounteten Komponente alle interaktiven Elemente tabbbar', async () => {
    await fc.assert(
      fc.asyncProperty(
        // Generator ueber die Menge der gemounteten Komponenten.
        fc.nat({ max: CASES.length - 1 }),
        async (index) => {
          const testCase = CASES[index]
          const i18n = createI18n('de')
          const router = makeRouter()
          const wrapper = await testCase.mount(i18n, router)
          try {
            const interactive = collectInteractive(wrapper.element)

            // Property 12: die Menge der tabbbaren Elemente je View ist nicht leer.
            expect(
              interactive.length,
              `${testCase.name}: keine interaktiven Elemente gefunden`,
            ).toBeGreaterThan(0)

            // Jedes gefundene interaktive Element MUSS erreichbar (tabbbar) sein;
            // ein der Tab-Reihenfolge entzogenes Element -> Fehlschlag (Req 9.6).
            for (const el of interactive) {
              expect(
                isTabbable(el),
                `${testCase.name}: <${el.tagName.toLowerCase()}>` +
                  (el.getAttribute('tabindex') !== null
                    ? ` [tabindex=${el.getAttribute('tabindex')}]`
                    : '') +
                  ' ist nicht per Tastatur erreichbar',
              ).toBe(true)
            }
          } finally {
            wrapper.unmount()
            document.documentElement.removeAttribute('lang')
          }
        },
      ),
      { numRuns: 100 },
    )
  })
})
