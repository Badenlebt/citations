import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import * as sass from 'sass'

// =============================================================================
// Task 8.1 — Property-Test: Fokusindikatoren erfüllen Kontrast
//
// Feature: ui-redesign, Property 7: Fokusindikatoren erfüllen Kontrast
//
// Design (design.md, Property 7): *Für alle* fokussierbaren interaktiven
// Elemente gilt: der sichtbare Fokusindikator weist gegenüber dem angrenzenden
// Hintergrund ein Kontrastverhältnis von mindestens 3:1 auf.
//
// Validates: Requirements 6.4, 9.4
// -----------------------------------------------------------------------------
//
// jsdom-Einschränkung (bestimmt die Testmethodik, konsistent mit den
// Schwester-SCSS-Tests contrast.test.ts / AppHeader.layout.test.ts /
// MobileOverflow.property.test.ts):
//
// Vue `<style scoped>` wird im Vitest/jsdom-Umfeld nicht zu angewandten Styles
// kompiliert — `getComputedStyle` auf gemounteten Komponenten liefert die
// SCSS-Regeln nicht zurück. Daher kompilieren wir die realen Stylesheet-Quellen
// mit `sass` zu CSS und prüfen die deklarierten Fokusindikatoren direkt auf der
// kompilierten CSS. Das ist robuster als eine Regex über rohe SCSS-Quelle:
// verschachtelte `&:focus-visible`-Blöcke und `#{…}`-Interpolationen (deren
// schließende Klammer eine naive `[^}]*`-Erfassung vorzeitig beendet) werden
// durch den Compiler korrekt zu flachen Regeln aufgelöst.
//
// Fokusindikatoren werden als `outline` auf `:focus-visible` gezeichnet und —
// weil jede Regel ein `outline-offset` trägt — auf dem Anwendungshintergrund
// (Gunmetal-Seiten-/Headerfläche, Req 4.1) dargestellt. Die Design-Konvention
// (design.md, Testing Strategy) ist: die Fokus-outline nutzt den Primär-/
// Akzent-Token (#C15BEB) gegen den Hintergrund-Token (#282D37).
//
// Quellen der Indikator-Paare (outline-Farbe → angrenzender Hintergrund):
//   - src/scss/_base.scss                   globales :focus-visible
//   - src/components/AppHeader.vue           Nav-Toggle
//   - src/components/ScrollToTop.vue         Scroll-to-Top-Schaltfläche
//   - src/components/LanguageSwitcher.vue    Sprach-<select>
//   - src/components/LengthLimitedInput.vue  Textfeld
//
// Pfadauflösung erfolgt — wie in den Schwester-Tests — über `process.cwd()`
// (Vitest nutzt das frontend-Paket als cwd).
// =============================================================================

const frontendRoot = process.cwd()
const scssDir = resolve(frontendRoot, 'src/scss')
const componentsDir = resolve(frontendRoot, 'src/components')
const srcDir = resolve(frontendRoot, 'src')

function normaliseHex(hex: string): string {
  let value = hex.replace('#', '').toLowerCase()
  if (value.length === 3) {
    value = value
      .split('')
      .map((c) => c + c)
      .join('')
  }
  return '#' + value
}

// --- Token-Auflösung über die kompilierte :root aus _variables.scss ----------
// Einzige Quelle der Wahrheit: wir lesen die Zielwerte aus den auf :root
// gespiegelten Custom-Properties, statt Farbliterale im Test zu duplizieren.
const variablesCss = sass.compile(resolve(scssDir, '_variables.scss')).css

function rootToken(name: string): string {
  const rootMatch = variablesCss.match(/:root\s*\{([^}]*)\}/)
  if (!rootMatch) {
    throw new Error(':root-Block nicht in kompilierter _variables.scss gefunden')
  }
  const match = rootMatch[1].match(new RegExp('--' + name + '\\s*:\\s*([^;]+);'))
  if (!match) {
    throw new Error('Token --' + name + ' nicht in :root gefunden')
  }
  return match[1].trim()
}

const tokenHex: Record<string, string> = {
  'color-primary': normaliseHex(rootToken('color-primary')),
  'color-background': normaliseHex(rootToken('color-background')),
}

// --- SCSS → CSS Kompilierung -------------------------------------------------
/** Extrahiert den Inhalt des ersten `<style ... lang="scss">`-Blocks einer SFC. */
function extractScopedScss(sfcPath: string): string {
  const source = readFileSync(sfcPath, 'utf8')
  const match = source.match(/<style[^>]*lang="scss"[^>]*>([\s\S]*?)<\/style>/)
  if (!match) {
    throw new Error('kein <style lang="scss">-Block in ' + sfcPath + ' gefunden')
  }
  return match[1]
}

/**
 * Kompiliert eine scoped-SCSS-Quelle einer SFC zu CSS. Die SFCs referenzieren
 * die Token-/Mixin-Stylesheets komponentenrelativ (`@use '../scss/…'`) oder über
 * den Vite-Alias `@/` (`@use '@/scss/…'`). `sass` kennt den Alias nicht, daher
 * wird das `@/`-Präfix vor dem Kompilieren entfernt; `loadPaths` deckt die
 * relevanten Verzeichnisse ab, sodass die Imports exakt wie im Vite-Build
 * auflösen.
 */
function compileScoped(sfcPath: string): string {
  const scss = extractScopedScss(sfcPath).replace(/(@use\s+['"])@\//g, '$1')
  return sass.compileString(scss, {
    url: pathToFileURL(sfcPath),
    loadPaths: [componentsDir, scssDir, srcDir],
  }).css
}

/** Kompiliert ein eigenständiges SCSS-Stylesheet (z. B. _base.scss) zu CSS. */
function compileStylesheet(path: string): string {
  return sass.compile(path, { loadPaths: [scssDir, srcDir] }).css
}

// --- Fokusindikator-Extraktion aus kompilierter CSS --------------------------
/**
 * Zerlegt eine kompilierte CSS-Quelle in Top-Level-Regeln via Klammer-Balancing
 * (robust gegenüber verschachtelten Regeln, z. B. innerhalb von Media-Queries).
 */
function topLevelRules(css: string): string[] {
  const rules: string[] = []
  let depth = 0
  let start = 0
  for (let i = 0; i < css.length; i++) {
    const char = css[i]
    if (char === '{') {
      if (depth === 0) {
        rules.push(css.slice(start, i + 1))
      }
      depth++
    } else if (char === '}') {
      depth--
      if (depth === 0) {
        rules.push(css.slice(start, i + 1))
        start = i + 1
      }
    }
  }
  return rules
}

/**
 * Findet in einer kompilierten CSS-Quelle den/die Regel-Deklarationsblock/-blöcke,
 * die einen `:focus-visible`-Selektor tragen UND eine `outline`-Deklaration
 * enthalten. Media-Query-Rahmen werden mitbetrachtet, indem ihr Inhalt rekursiv
 * durchsucht wird. Rückgabe: die Deklarationsblöcke (Inhalt zwischen `{` und `}`)
 * aller passenden `:focus-visible`-Regeln.
 */
function focusOutlineBlocks(css: string): string[] {
  const blocks: string[] = []
  const atBlock = /@media[^{]*\{/
  for (const rule of topLevelRules(css)) {
    const headerMatch = rule.match(/^([^{]*)\{([\s\S]*)\}$/)
    if (!headerMatch) {
      continue
    }
    const selector = headerMatch[1]
    const body = headerMatch[2]
    if (atBlock.test(rule.slice(0, rule.indexOf('{') + 1))) {
      // Verschachtelte Regeln innerhalb einer @media-Query rekursiv prüfen.
      blocks.push(...focusOutlineBlocks(body))
      continue
    }
    if (/:focus-visible/.test(selector) && /(?:^|[;{\s])outline\s*:/.test(body)) {
      blocks.push(body)
    }
  }
  return blocks
}

/**
 * Löst die outline-Farbe eines kompilierten `:focus-visible`-Blocks zu einem
 * normalisierten Hex-Wert auf. Unterstützt beide Schreibweisen, die der
 * Compiler erzeugt: `var(--color-primary, …)` (Custom-Property bleibt erhalten;
 * aufgelöst über die :root-Tokens) und einen direkten Hex-Wert (aus
 * `tokens.$color-primary`).
 */
function resolveOutlineColor(block: string, label: string): string {
  const outline = block.match(/(?:^|[;{\s])outline\s*:\s*([^;]+);/)
  if (!outline) {
    throw new Error('keine outline-Deklaration im :focus-visible-Block von ' + label)
  }
  // Die outline-Kurzform ist `<Breite> <Stil> <Farbe>`. Nicht-farbliche Tokens
  // (z. B. die Breite `var(--spacing-xs, …)` in AppHeader) dürfen die Farb-
  // auflösung nicht fälschen — wir ziehen gezielt einen Farb-Token (`--color-…`)
  // bzw. einen direkten Hex-Wert aus der Deklaration.
  const decl = outline[1]
  const colorVar = decl.match(/var\(\s*--(color-[\w-]+)/)
  if (colorVar) {
    return normaliseHex(rootToken(colorVar[1]))
  }
  const hexMatch = decl.match(/#[0-9a-fA-F]{3,6}\b/)
  if (hexMatch) {
    return normaliseHex(hexMatch[0])
  }
  throw new Error('outline-Farbe ist nicht token-basiert in ' + label + ': ' + decl.trim())
}

// --- WCAG 2.1 Kontrastberechnung (relative Luminanz) -------------------------
// https://www.w3.org/TR/WCAG21/#dfn-relative-luminance
function channelLuminance(value8bit: number): number {
  const c = value8bit / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

function relativeLuminance(hex: string): number {
  const h = normaliseHex(hex).slice(1)
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return (
    0.2126 * channelLuminance(r) +
    0.7152 * channelLuminance(g) +
    0.0722 * channelLuminance(b)
  )
}

/** WCAG-Kontrastverhältnis (lighter + 0.05) / (darker + 0.05), Bereich 1..21. */
function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)
  const lighter = Math.max(la, lb)
  const darker = Math.min(la, lb)
  return (lighter + 0.05) / (darker + 0.05)
}

// --- Sammlung aller sichtbaren Fokusindikatoren ------------------------------
// Jede Quelle trägt genau einen outline-basierten :focus-visible-Indikator bei.
// Der angrenzende Hintergrund ist der Anwendungshintergrund-Token (die Fläche,
// auf die die versetzte outline gezeichnet wird, Req 4.1/9.4).
const BACKGROUND_TOKEN = 'color-background'

interface FocusIndicator {
  readonly source: string
  readonly outlineHex: string
  readonly backgroundToken: string
}

interface IndicatorSource {
  readonly file: string
  readonly css: string
}

const indicatorSources: readonly IndicatorSource[] = [
  { file: 'src/scss/_base.scss', css: compileStylesheet(resolve(scssDir, '_base.scss')) },
  { file: 'src/components/AppHeader.vue', css: compileScoped(resolve(componentsDir, 'AppHeader.vue')) },
  { file: 'src/components/ScrollToTop.vue', css: compileScoped(resolve(componentsDir, 'ScrollToTop.vue')) },
  { file: 'src/components/LanguageSwitcher.vue', css: compileScoped(resolve(componentsDir, 'LanguageSwitcher.vue')) },
  { file: 'src/components/LengthLimitedInput.vue', css: compileScoped(resolve(componentsDir, 'LengthLimitedInput.vue')) },
]

const indicators: readonly FocusIndicator[] = indicatorSources.map(({ file, css }) => {
  const blocks = focusOutlineBlocks(css)
  if (blocks.length === 0) {
    throw new Error('kein :focus-visible-Block mit outline in ' + file + ' gefunden')
  }
  // Der erste outline-tragende :focus-visible-Block definiert den Indikator.
  return {
    source: file,
    outlineHex: resolveOutlineColor(blocks[0], file),
    backgroundToken: BACKGROUND_TOKEN,
  }
})

const MIN_FOCUS_CONTRAST = 3

describe('Fokusindikator-Kontrast (Property 7)', () => {
  it('discovered a focus indicator in every expected source', () => {
    // Belegt die Parsing-Grundlage der Property: jede gelistete Quelle trägt
    // genau einen outline-basierten Fokusindikator bei.
    expect(indicators.map((i) => i.source)).toEqual(
      indicatorSources.map((s) => s.file),
    )
    expect(indicators.length).toBeGreaterThan(0)
  })

  it('every focus indicator resolves to a defined background token', () => {
    for (const indicator of indicators) {
      expect(tokenHex[indicator.backgroundToken], indicator.source + ' background token').toBeDefined()
      expect(indicator.outlineHex, indicator.source + ' outline colour').toMatch(/^#[0-9a-f]{6}$/)
    }
  })

  // Feature: ui-redesign, Property 7: Fokusindikatoren erfüllen Kontrast
  // Für alle fokussierbaren interaktiven Elemente gilt: der sichtbare
  // Fokusindikator weist gegenüber dem angrenzenden Hintergrund ein
  // Kontrastverhältnis von mindestens 3:1 auf. Die Indikator-Paare
  // (outline-Farbe → angrenzender Hintergrund) stammen aus _base.scss
  // (globales :focus-visible) sowie den SFCs AppHeader, ScrollToTop,
  // LanguageSwitcher und LengthLimitedInput; Tokens werden über
  // _variables.scss aufgelöst. #C15BEB auf #282D37 ergibt ca. 3,92:1.
  it('every visible focus indicator meets 3:1 against the adjacent background', () => {
    fc.assert(
      fc.property(fc.constantFrom(...indicators), (indicator) => {
        const outline = indicator.outlineHex
        const background = tokenHex[indicator.backgroundToken]
        const ratio = contrastRatio(outline, background)
        expect(
          ratio,
          indicator.source +
            ' focus outline (' +
            outline +
            ' on ' +
            background +
            ') must meet ' +
            MIN_FOCUS_CONTRAST +
            ':1 but was ' +
            ratio.toFixed(3) +
            ':1',
        ).toBeGreaterThanOrEqual(MIN_FOCUS_CONTRAST)
      }),
      { numRuns: 100 },
    )
  })
})
