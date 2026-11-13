import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { App } from 'vue'
import * as sass from 'sass'

// Task 6.10 — Beispieltest: Interaktionszustaende und Fehler-/Fallback-Verhalten
// (Req 2.5, 6.2, 6.5, 6.6).
//
// Geprueft werden vier konkrete Beispiele:
//   1. Hover      — Links nutzen die Hover-Farbe #C15BEB (via --color-primary)
//                   mit 0.3s ease (aus dem transition()-Mixin).      (Req 6.2)
//   2. Deaktiviert — LengthLimitedInput __field:disabled besitzt einen vom
//                   Normalzustand unterscheidbaren Zustand (opacity/cursor). (Req 6.5)
//   3. Fehler     — Ungueltiges Pflichtfeld -> Fehlerzustand + Meldung ueber
//                   bestehende errors.*-i18n-Schluessel (AdminLogin).   (Req 6.6)
//   4. Fallback   — Unbekannte Farbe -> sichtbarer Fallback: die var()-Aufrufe
//                   tragen #fff / #282D37 als Rueckfallwert.            (Req 2.5)
//
// Ansatz je Teilpruefung (dokumentiert):
// Vue `<style scoped>` wird im Vitest/jsdom-Umfeld NICHT zu angewandten Styles
// kompiliert; getComputedStyle liefert die SCSS-Regeln nicht zurueck. Fuer die
// Styling-Pruefungen (1, 2, 4) wird daher die SCSS-Quelle direkt mit `sass`
// kompiliert und die resultierende CSS geparst — identisch zum Vorgehen in
// AppHeader.layout.test.ts. Nur die Fehlermeldungs-Pruefung (3) ist ein echter
// Mount-Test, da sie Laufzeitverhalten (i18n-Aufloesung) betrifft.

const frontendRoot = process.cwd()
const scssDir = resolve(frontendRoot, 'src/scss')
const componentsDir = resolve(frontendRoot, 'src/components')
const srcDir = resolve(frontendRoot, 'src')

// --- SCSS-Hilfen (analog AppHeader.layout.test.ts) ---------------------------

/** Extrahiert den Inhalt des ersten `<style ... lang="scss" ...>`-Blocks einer SFC. */
function extractScopedScss(sfcPath: string): string {
  const source = readFileSync(sfcPath, 'utf8')
  const match = source.match(/<style[^>]*lang="scss"[^>]*>([\s\S]*?)<\/style>/)
  if (!match) {
    throw new Error('kein <style lang="scss">-Block in ' + sfcPath + ' gefunden')
  }
  return match[1]
}

/** Kompiliert eine scoped-SCSS-Quelle zu CSS (loest den `@/`-Alias auf). */
function compileScoped(scss: string): string {
  const withoutAlias = scss.replace(/(@use\s+['"])@\//g, '$1')
  return sass.compileString(withoutAlias, {
    loadPaths: [componentsDir, scssDir, srcDir],
  }).css
}

/** Isoliert den Deklarationsblock einer Regel mit exakt passendem Selektor. */
function ruleBlock(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const re = new RegExp('(?:^|[}])\\s*' + escaped + '\\s*\\{([^}]*)\\}')
  const match = css.match(re)
  if (!match) {
    throw new Error('Regel fuer "' + selector + '" nicht in kompilierter CSS gefunden')
  }
  return match[1]
}

/** Liest einen Eigenschaftswert aus einem Deklarationsblock. */
function declaration(block: string, property: string): string {
  const match = block.match(
    new RegExp('(?:^|[;{]|\\s)' + property + '\\s*:\\s*([^;]+);'),
  )
  if (!match) {
    throw new Error('Eigenschaft "' + property + '" nicht im Regelblock deklariert')
  }
  return match[1].trim()
}

// --- Kompilate der relevanten Stylesheets ------------------------------------
// _base.scss ist ein globales Stylesheet (kein SFC): direkt kompilieren.
const baseCss = sass.compile(resolve(scssDir, '_base.scss')).css
// LengthLimitedInput.vue traegt seine Interaktionszustaende im scoped Block.
const inputCss = compileScoped(
  extractScopedScss(resolve(componentsDir, 'LengthLimitedInput.vue')),
)

describe('Hover-Interaktion: Links nutzen #C15BEB mit 0.3s ease (Req 6.2)', () => {
  // Ansatz: _base.scss kompiliert; a:hover und der a-Transition-Block werden
  // aus der CSS geparst. Die Hover-Farbe wird als var(--color-primary, #C15BEB)
  // deklariert; der 0.3s-ease-Uebergang stammt aus dem transition()-Mixin.
  it('faerbt a:hover auf die Primaerfarbe #C15BEB (Fallback im var())', () => {
    // Sass gruppiert die Hover-/Focus-Regel als "a:hover, a:focus-visible".
    const block = ruleBlock(baseCss, 'a:hover, a:focus-visible')
    const colorExpr = declaration(block, 'color')
    // var(--color-primary, #C15BEB): der Fallback belegt die Zielfarbe #C15BEB.
    expect(colorExpr).toMatch(/--color-primary/)
    expect(colorExpr.toLowerCase()).toContain('#c15beb')
  })

  it('setzt auf a einen 0.3s-ease-Uebergang (aus transition()-Mixin)', () => {
    const block = ruleBlock(baseCss, 'a')
    const transition = declaration(block, 'transition')
    expect(transition).toBe('color 0.3s ease')
  })
})

describe('Deaktiviert-Zustand: LengthLimitedInput __field:disabled (Req 6.5)', () => {
  // Ansatz: scoped SCSS der SFC kompiliert; die :disabled-Regel wird geparst.
  // Der Deaktiviert-Zustand ist vom Normalzustand unterscheidbar (opacity +
  // cursor: not-allowed) und sperrt die Interaktion visuell.
  it('reduziert die Deckkraft und setzt cursor: not-allowed', () => {
    const block = ruleBlock(inputCss, '.length-limited-input__field:disabled')
    expect(declaration(block, 'cursor')).toBe('not-allowed')
    const opacity = Number(declaration(block, 'opacity'))
    expect(opacity).toBeGreaterThan(0)
    expect(opacity).toBeLessThan(1)
  })
})

describe('Fallback: unbekannte Farbe -> sichtbarer Rueckfallwert (Req 2.5)', () => {
  // Ansatz: _base.scss kompiliert; das Muster var(--color-x, <fallback>) traegt
  // laut Design die Rueckfallwerte #fff (Text) und #282D37 (Hintergrund). Faellt
  // ein Token aus, bleibt das Element ueber den Fallback sichtbar.
  it('belegt den Text-Fallback #fff im body-color-var()', () => {
    const block = ruleBlock(baseCss, 'body')
    const colorExpr = declaration(block, 'color')
    expect(colorExpr).toMatch(/var\(\s*--color-text\s*,\s*#fff\s*\)/i)
  })

  it('belegt den Hintergrund-Fallback #282D37 im body-background-var()', () => {
    const block = ruleBlock(baseCss, 'body')
    const bgExpr = declaration(block, 'background-color')
    expect(bgExpr).toMatch(/var\(\s*--color-background\s*,\s*#282D37\s*\)/i)
  })
})

// -----------------------------------------------------------------------------
// Fehlerzustand + Meldung ueber bestehende errors.*-Schluessel (Req 6.6)
// -----------------------------------------------------------------------------
// Ansatz: echter Mount-Test, da die Fehlermeldung Laufzeitverhalten ist
// (API-Fehler -> apiErrorMessage -> i18n-Aufloesung). `@/api` wird gemockt, um
// api.login einen ApiError mit stabilem Fehlercode werfen zu lassen; die
// AdminLogin-Komponente rendert daraufhin die zum Code gehoerende Meldung aus
// den bereits vorhandenen errors.*-Schluesseln. Es werden ausschliesslich
// bestehende Schluessel verwendet (INVALID_CREDENTIALS / MISSING_CREDENTIALS).

vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: {
      login: vi.fn(),
    },
  }
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = (await import('@/api')).api as any
const { ApiError } = await import('@/api')
const { messages } = await import('@/i18n')
const AdminLogin = (await import('./admin/AdminLogin.vue')).default
const { createI18n, installI18n } = await import('@/i18n')

// In-memory localStorage stub, damit createI18n/setLocale nicht wirft.
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

// Pin auf Deutsch, damit die erwarteten errors.*-Texte deterministisch sind.
const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

describe('Fehlerzustand: ungueltiges Pflichtfeld -> Meldung via errors.* (Req 6.6)', () => {
  beforeEach(() => {
    stubStorage()
    mockedApi.login.mockReset()
  })

  afterEach(() => {
    vi.clearAllMocks()
    vi.unstubAllGlobals()
  })

  it('zeigt die INVALID_CREDENTIALS-Meldung mit role="alert" an', async () => {
    mockedApi.login.mockRejectedValue(
      new ApiError(401, 'INVALID_CREDENTIALS', 'invalid'),
    )
    const wrapper = mount(AdminLogin, { global: { plugins: [i18nPlugin] } })

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const alert = wrapper.get('.admin-login__error')
    expect(alert.attributes('role')).toBe('alert')
    // Meldung stammt aus dem bestehenden errors.INVALID_CREDENTIALS-Schluessel.
    expect(alert.text()).toBe(messages.de.errors.INVALID_CREDENTIALS)
  })

  it('zeigt die MISSING_CREDENTIALS-Meldung bei fehlenden Pflichtfeldern an', async () => {
    mockedApi.login.mockRejectedValue(
      new ApiError(400, 'MISSING_CREDENTIALS', 'missing'),
    )
    const wrapper = mount(AdminLogin, { global: { plugins: [i18nPlugin] } })

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const alert = wrapper.get('.admin-login__error')
    expect(alert.text()).toBe(messages.de.errors.MISSING_CREDENTIALS)
  })

  it('setzt den Fehlerzustand __error ueber die Status-Fehlerfarbe (Req 6.6)', () => {
    // Styling-Seite des Fehlerzustands: die scoped SCSS der AdminLogin-SFC
    // faerbt .admin-login__error ueber das color-error-Token (ersetzt #b00020),
    // visuell unterscheidbar vom Normalzustand.
    const loginCss = compileScoped(
      extractScopedScss(resolve(componentsDir, 'admin/AdminLogin.vue')),
    )
    const block = ruleBlock(loginCss, '.admin-login__error')
    expect(declaration(block, 'color')).toMatch(/--color-error/)
  })
})
