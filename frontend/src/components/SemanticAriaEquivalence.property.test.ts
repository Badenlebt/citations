import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import fc from 'fast-check'
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'
import ScrollToTop from './ScrollToTop.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import AdminLogin from './admin/AdminLogin.vue'
import { routes } from '@/router'
import { createI18n, installI18n, type I18n } from '@/i18n'

// In-memory localStorage stub so LanguageSwitcher's setLocale never throws and
// the components mount cleanly in jsdom (same pattern as AppHeader.test.ts /
// LanguageSwitcher.test.ts).
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

// -----------------------------------------------------------------------------
// Referenzmodell: Semantik "vor/nach Redesign" (Property 11, Req 9.2)
// -----------------------------------------------------------------------------
// Das Redesign hat ausschliesslich Styling veraendert, nicht das Markup bzw. die
// Semantik (Req 8, 9.2). Daher wird der Zustand "vor dem Redesign" durch eine
// stabile, explizit hinterlegte Referenzmenge der bekannten semantischen
// Merkmale je Komponente modelliert. Fuer jedes generierte Merkmal muss das
// gerenderte, Accessibility-relevante DOM es aequivalent enthalten; fehlt oder
// weicht ein Merkmal ab, schlaegt der Test fehl.
//
// Zugaengliche Namen werden ausschliesslich ueber bestehende i18n-Schluessel
// (DE-Katalog) bzw. die bereits im Markup vorhandenen technisch-neutralen
// aria-label geprueft — es werden KEINE neuen i18n-Schluessel eingefuehrt.

type SemanticFeature =
  // Rolle implizit ueber Elementtyp: ein Element dieses Tags (optional mit
  // Textinhalt bzw. type-Attribut) muss existieren.
  | { kind: 'element'; selector: string; text?: string }
  // Zugaenglicher Name ueber aria-label an einem Element des gegebenen Selektors.
  | { kind: 'ariaLabel'; selector: string; label: string }
  // Zustand: Attribut mit erwartetem Wert an einem Element des Selektors.
  | { kind: 'state'; selector: string; attr: string; value: string }

interface ComponentRef {
  name: string
  // Baut eine frisch gemountete Instanz samt erwarteter Merkmalsmenge.
  mount: () => Promise<{ wrapper: VueWrapper; features: SemanticFeature[] }>
}

// DE-Katalog als Referenz fuer zugaengliche Namen/Textinhalte.
const DE = createI18n('de')

const components: ComponentRef[] = [
  {
    name: 'AppHeader',
    mount: async () => {
      const router = makeRouter()
      await router.push('/')
      await router.isReady()
      const wrapper = mount(AppHeader, {
        global: { plugins: [router, (app) => installI18n(app, createI18n('de'))] },
      })
      await wrapper.vm.$nextTick()
      return {
        wrapper,
        features: [
          // Rollen (implizit ueber Elementtyp): Banner (header), Navigation,
          // Hamburger-Button.
          { kind: 'element', selector: 'header.app-header' },
          { kind: 'element', selector: 'nav.app-header__nav' },
          { kind: 'element', selector: 'button.app-header__toggle' },
          // Zugaengliche Namen ueber bestehende i18n-Schluessel bzw. das bereits
          // vorhandene technisch-neutrale aria-label des Toggles.
          { kind: 'element', selector: '.app-header__title', text: DE.t('app.title') },
          { kind: 'ariaLabel', selector: 'button.app-header__toggle', label: 'Toggle navigation menu' },
          { kind: 'ariaLabel', selector: 'nav.app-header__nav', label: DE.t('app.title') },
          // Navigationseintraege (Links) mit Namen aus i18n.
          { kind: 'element', selector: 'a.app-header__nav-link[href="/"]', text: DE.t('nav.search') },
          { kind: 'element', selector: 'a.app-header__nav-link[href="/admin"]', text: DE.t('nav.admin') },
          // Zustand: aria-expanded am Hamburger-Button (geschlossen nach Mount).
          { kind: 'state', selector: 'button.app-header__toggle', attr: 'aria-expanded', value: 'false' },
        ],
      }
    },
  },
  {
    name: 'LanguageSwitcher',
    mount: async () => {
      const i18n: I18n = createI18n('de')
      const wrapper = mount(LanguageSwitcher, {
        global: { plugins: [(app) => installI18n(app, i18n)] },
      })
      await wrapper.vm.$nextTick()
      return {
        wrapper,
        features: [
          // Gruppen-Semantik: role="group" mit zugaenglichem Namen aus dem
          // bestehenden i18n-Schluessel language.label. Die Locales sind
          // Umschalt-Buttons (aria-pressed markiert die aktive Locale) mit
          // zugaenglichem Namen aus den bestehenden language.*-Schluesseln.
          { kind: 'element', selector: '.language-switcher' },
          { kind: 'ariaLabel', selector: '.language-switcher', label: DE.t('language.label') },
          { kind: 'state', selector: '.language-switcher', attr: 'role', value: 'group' },
          { kind: 'ariaLabel', selector: '.language-switcher__option--active', label: DE.t('language.de') },
          { kind: 'state', selector: '.language-switcher__option--active', attr: 'aria-pressed', value: 'true' },
        ],
      }
    },
  },
  {
    name: 'AppFooter',
    mount: async () => {
      const router = makeRouter()
      await router.push('/')
      await router.isReady()
      const wrapper = mount(AppFooter, {
        global: { plugins: [router, (app) => installI18n(app, createI18n('de'))] },
      })
      await wrapper.vm.$nextTick()
      return {
        wrapper,
        features: [
          // Rolle: contentinfo (footer) mit zwei Navigationsspalten, jede mit
          // Gruppennamen (aria-label), Ueberschrift und einer Liste echter
          // Links. Alle Namen stammen aus bestehenden i18n-Schluesseln.
          { kind: 'element', selector: 'footer.app-footer' },
          // Zitate-Spalte: Gruppenname app.title, Ueberschrift ebenso, Links
          // auf die Suche.
          { kind: 'ariaLabel', selector: 'nav.app-footer__col[aria-label="Zitate"]', label: DE.t('app.title') },
          { kind: 'element', selector: 'nav.app-footer__col[aria-label="Zitate"] .app-footer__heading', text: DE.t('app.title') },
          { kind: 'element', selector: 'nav.app-footer__col[aria-label="Zitate"] a.app-footer__link', text: DE.t('search.heading') },
          // Verwaltungs-Spalte: Gruppenname nav.admin, Deep-Links auf die
          // Verwaltungsbereiche (?tab=...).
          { kind: 'ariaLabel', selector: 'nav.app-footer__col[aria-label="Verwaltung"]', label: DE.t('nav.admin') },
          { kind: 'element', selector: 'nav.app-footer__col[aria-label="Verwaltung"] .app-footer__heading', text: DE.t('nav.admin') },
          { kind: 'element', selector: 'a.app-footer__link[href="/admin?tab=sources"]', text: DE.t('admin.tabSources') },
        ],
      }
    },
  },
  {
    name: 'ScrollToTop',
    mount: async () => {
      const wrapper = mount(ScrollToTop)
      await wrapper.vm.$nextTick()
      return {
        wrapper,
        features: [
          // Rolle: Button; zugaenglicher Name ueber das bereits vorhandene,
          // technisch-neutrale aria-label (kein i18n-Schluessel, wie im Markup).
          { kind: 'element', selector: 'button.scroll-to-top' },
          { kind: 'state', selector: 'button.scroll-to-top', attr: 'type', value: 'button' },
          { kind: 'ariaLabel', selector: 'button.scroll-to-top', label: 'Scroll to top' },
          // Dekoratives Icon ist der AT entzogen (aria-hidden).
          { kind: 'state', selector: '.scroll-to-top__icon', attr: 'aria-hidden', value: 'true' },
        ],
      }
    },
  },
  {
    name: 'AdminLogin',
    mount: async () => {
      const wrapper = mount(AdminLogin, {
        global: { plugins: [(app) => installI18n(app, createI18n('de'))] },
      })
      await wrapper.vm.$nextTick()
      return {
        wrapper,
        features: [
          // Rollen: Formular, Ueberschrift (h2), label+input, Submit-Button.
          { kind: 'element', selector: 'form.admin-login' },
          { kind: 'element', selector: 'h2.admin-login__heading', text: DE.t('admin.loginHeading') },
          { kind: 'element', selector: 'input[name="username"]' },
          { kind: 'element', selector: 'input[name="password"]' },
          { kind: 'element', selector: 'button[type="submit"]', text: DE.t('admin.login') },
          // Zugaengliche Namen der Felder ueber die Label-Texte (i18n).
          { kind: 'element', selector: 'label.admin-login__field', text: DE.t('admin.username') },
        ],
      }
    },
  },
]

// Prueft ein einzelnes erwartetes Merkmal gegen das gerenderte DOM. Ein
// fehlendes/abweichendes Merkmal fuehrt zu einem AssertionError (Fehlschlag).
function assertFeature(wrapper: VueWrapper, feature: SemanticFeature): void {
  const el = wrapper.find(feature.selector)
  expect(el.exists(), `missing element for selector "${feature.selector}"`).toBe(true)

  if (feature.kind === 'element') {
    if (feature.text !== undefined) {
      expect(el.text()).toContain(feature.text)
    }
    return
  }

  if (feature.kind === 'ariaLabel') {
    expect(el.attributes('aria-label')).toBe(feature.label)
    return
  }

  // feature.kind === 'state'
  expect(el.attributes(feature.attr)).toBe(feature.value)
}

describe('Semantic/ARIA equivalence across central components (Property 11)', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  // Feature: ui-redesign, Property 11: Semantik und ARIA bleiben äquivalent
  // Fuer jede Komponente und jedes in der stabilen Referenzmenge hinterlegte
  // semantische Merkmal (Rolle via Elementtyp, zugaenglicher Name via
  // aria-label/i18n-Textinhalt, Zustand via aria-*/type) gilt: das gerenderte,
  // Accessibility-relevante DOM enthaelt das Merkmal aequivalent (Req 9.2). Das
  // Markup wurde im Redesign bewusst nicht veraendert; die Referenzmenge
  // modelliert damit den Zustand "vor/nach Redesign". Es werden ausschliesslich
  // bestehende i18n-Schluessel verwendet.
  it('preserves roles, names and states for every referenced feature', async () => {
    // Flache Menge der Paare (Komponenten-Index × Merkmals-Index), ueber die
    // fast-check quantifiziert.
    const pairs: Array<{ c: number; f: number }> = []
    for (let c = 0; c < components.length; c++) {
      // Merkmalsanzahl deterministisch aus einer Probe-Montage ableiten.
      const { wrapper, features } = await components[c].mount()
      wrapper.unmount()
      for (let f = 0; f < features.length; f++) {
        pairs.push({ c, f })
      }
    }

    await fc.assert(
      fc.asyncProperty(fc.constantFrom(...pairs), async ({ c, f }) => {
        const { wrapper, features } = await components[c].mount()
        try {
          assertFeature(wrapper, features[f])
        } finally {
          wrapper.unmount()
        }
      }),
      { numRuns: 100 },
    )
  })
})
