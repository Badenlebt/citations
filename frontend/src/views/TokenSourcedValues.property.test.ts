import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import * as sass from 'sass'

// =============================================================================
// Task 6.8 — Property-Test: Alle Gestaltungswerte stammen aus Tokens
//
// Feature: ui-redesign, Property 1: Alle Gestaltungswerte stammen aus Tokens
//
// Design (design.md, Property 1): *Für alle* sichtbaren Oberflächenelemente der
// Kategorien App-Header, Inhaltsbereich, Schaltflächen, Verknüpfungen und
// Formularelemente gilt: jeder verwendete Farb-, Abstands-, Radius- und
// Schattenwert ist Element der definierten Token-Menge; es existiert kein fest
// einkodierter Wert außerhalb der Tokens.
//
// Validates: Requirements 1.3, 2.2, 4.6, 5.2, 5.3, 5.4, 5.5, 5.6
// -----------------------------------------------------------------------------
//
// jsdom-Einschränkung (dokumentiert, da sie die Testmethodik bestimmt):
//
// Vue `<style scoped>` wird im Vitest/jsdom-Umfeld NICHT zu angewandten Styles
// kompiliert — die `[data-v-…]`-Attributselektoren und das SCSS→CSS-Transpiling
// entstehen erst im Vite-Build, und `getComputedStyle` auf gemounteten
// Komponenten liefert die scoped Regeln nicht zurück. Ein DOM-basierter Test
// wäre daher nicht aussagekräftig. Wir arbeiten deshalb — konsistent zu den
// Schwester-Tests contrast.test.ts / AppHeader.layout.test.ts /
// MobileOverflow.property.test.ts — auf Quell-/kompilierter Ebene: wir
// extrahieren den `<style lang="scss">`-Block jeder restyleten SFC und
// kompilieren ihn mit `sass` zu CSS.
//
// Vorgehen (Property 1):
//   1. Die Token-Wertmenge (Farben, Abstände, Radius, Schatten, Breakpoints,
//      Typografie) wird aus dem kompilierten `:root`-Block von _variables.scss
//      geparst — die einzige Quelle der Wahrheit (keine duplizierten Literale).
//   2. fast-check wählt über die Menge der restyleten SFCs.
//   3. Pro SFC wird die kompilierte CSS auf Gestaltungswert-Literale gescannt
//      (Farben: Hex/rgba; Abstände/Radien: px/rem/em). Jeder gefundene Wert muss
//      entweder in der Token-Wertmenge liegen, Teil eines `var(--token…)`-
//      Ausdrucks sein (dessen Fallback wiederum ein Token-Wert ist) oder in der
//      dokumentierten Allowlist struktureller Ausnahmen stehen.
//   4. Ein interner Negativ-Check belegt, dass echte Hartkodierungen
//      (z. B. `#abc123`, `#666`) als Verstoß erkannt werden.
//
// Pfadauflösung erfolgt — wie in den Schwester-Tests — über `process.cwd()`
// (Vitest nutzt das frontend-Paket als cwd).
// =============================================================================

const frontendRoot = process.cwd()
const srcDir = resolve(frontendRoot, 'src')
const scssDir = resolve(srcDir, 'scss')
const componentsDir = resolve(srcDir, 'components')
const adminDir = resolve(componentsDir, 'admin')
const viewsDir = resolve(srcDir, 'views')

// -----------------------------------------------------------------------------
// Token-Wertmenge aus _variables.scss (einzige Quelle der Wahrheit)
// -----------------------------------------------------------------------------

/** Normalisiert einen Hex-Farbwert auf 6-stellig, kleingeschrieben. */
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

/** Kollabiert Whitespace, damit `rgba(0,  0, 0, .4)` vergleichbar wird. */
function compact(value: string): string {
  return value.replace(/\s+/g, '').toLowerCase()
}

const variablesCss = sass.compile(resolve(scssDir, '_variables.scss')).css

const rootMatch = variablesCss.match(/:root\s*\{([^}]*)\}/)
if (!rootMatch) {
  throw new Error(':root-Block nicht in kompilierter _variables.scss gefunden')
}
const rootBlock = rootMatch[1]

// Aus jedem Token-Wert die enthaltenen Gestaltungswerte sammeln.
const tokenColors = new Set<string>()
const tokenRgba = new Set<string>()
const tokenLengths = new Set<string>()

for (const decl of rootBlock.matchAll(/--([\w-]+)\s*:\s*([^;]+);/g)) {
  const value = decl[2].trim()
  for (const hex of value.match(/#[0-9a-fA-F]{3,6}\b/g) ?? []) {
    tokenColors.add(normaliseHex(hex))
  }
  for (const rgba of value.match(/rgba?\([^)]*\)/g) ?? []) {
    tokenRgba.add(compact(rgba))
  }
  // Reine Längen-Tokens (Abstände, Radien, Schriftgrößen, Breakpoints).
  if (/^\d*\.?\d+(px|rem|em)$/.test(value)) {
    tokenLengths.add(value)
  }
}

// -----------------------------------------------------------------------------
// Dokumentierte Allowlist struktureller / nicht-tokenisierter Ausnahmen
// -----------------------------------------------------------------------------
// Diese Werte sind bewusst zugelassen (siehe Task 6.8): strukturelle Maße,
// Rahmenbreiten, das Scrim des Lösch-Dialogs und relative Kompositionen.
const ALLOWED_RGBA = new Set<string>([
  compact('rgba(0,0,0,0.4)'), // Scrim des DeleteSourceDialog (kein Scrim-Token)
])

// Strukturelle Längen, die keine Abstands-/Radius-Gestaltung darstellen:
// Rahmen-/Umriss-Breiten, feste Layout-Maße und relative Schriftgrößen.
const ALLOWED_LENGTHS = new Set<string>([
  '0', // nullwert
  '1px', // Rahmen/Divider
  '2px', // Rahmen/Fokus/Unterstrich
  '3px', // Zitat-Rahmen
  '4px', // Schatten-Blur (innerhalb der Schatten-Tokens)
  '8px', // Schatten-Blur (innerhalb der Schatten-Tokens)
  '96px', // App.vue padding-top unter der fixierten Leiste (Req 4.3)
  '0.85em', // relative Meta-/Hinweis-Schriftgröße
  '0.9em', // relative Meta-Schriftgröße
  '24rem', // max-width Login-Formular
  '28rem', // max-width Sprach-Formular
  '32rem', // max-width Lösch-Dialog
  '40rem', // max-width Manager-Formulare
  '48rem', // max-width Admin-View
])

// -----------------------------------------------------------------------------
// SFC-Extraktion und -Kompilierung
// -----------------------------------------------------------------------------

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
 * Kompiliert den scoped-SCSS-Block einer SFC zu CSS. Die SFCs referenzieren die
 * Token-/Mixin-Stylesheets komponentenrelativ (`@use '../scss/…'`) oder über
 * den Vite-Alias `@/` (`@use '@/scss/…'`). `sass` kennt den Alias nicht, daher
 * wird das `@/`-Präfix vor dem Kompilieren entfernt; `loadPaths` deckt die
 * relevanten Verzeichnisse ab, sodass die Imports exakt wie im Vite-Build
 * auflösen. Der von `_variables.scss` emittierte `:root`-Block (die Token-
 * Definition selbst) wird entfernt — geprüft werden nur die Komponentenregeln.
 */
function compileComponentCss(sfcPath: string): string {
  const scss = extractScopedScss(sfcPath).replace(/(@use\s+['"])@\//g, '$1')
  const css = sass.compileString(scss, {
    url: pathToFileURL(sfcPath),
    loadPaths: [componentsDir, scssDir, srcDir],
  }).css
  return css.replace(/:root\s*\{[^}]*\}/g, '')
}

// -----------------------------------------------------------------------------
// Verstoß-Scanner: findet Gestaltungswerte außerhalb der Token-Menge
// -----------------------------------------------------------------------------

/**
 * Prüft eine kompilierte Komponenten-CSS auf fest einkodierte Gestaltungswerte.
 * Rückgabe: Liste menschenlesbarer Verstöße (leer = alles tokenbasiert).
 *
 * Erkannte Gestaltungswert-Literale:
 *   - Farben: Hex (`#rgb`/`#rrggbb`) und `rgb()/rgba()`.
 *   - Abstände/Radien: Längen mit Einheit `px`/`rem`/`em`.
 * Für jedes Literal gilt: gültig, wenn es (a) ein Token-Wert ist, (b) in der
 * dokumentierten Allowlist steht oder (c) der rgba-Wert eines Schatten-Tokens
 * ist. `var(--token…)`-Ausdrücke sind per Konstruktion gültig; ihr Fallback ist
 * selbst ein Token-Wert und wird über dieselbe Membership-Prüfung abgedeckt.
 */
function findHardcodedValues(css: string): string[] {
  const violations: string[] = []

  // Farben: Hex.
  for (const hex of css.match(/#[0-9a-fA-F]{3,6}\b/g) ?? []) {
    const normalised = normaliseHex(hex)
    if (!tokenColors.has(normalised)) {
      violations.push('Hartkodierte Farbe ' + hex + ' nicht in der Token-Menge')
    }
  }

  // Farben: rgb()/rgba() — Token-Schatten-rgba oder Scrim-Allowlist.
  for (const rgba of css.match(/rgba?\([^)]*\)/g) ?? []) {
    const key = compact(rgba)
    if (!tokenRgba.has(key) && !ALLOWED_RGBA.has(key)) {
      violations.push('Hartkodierter rgba-Wert ' + rgba + ' außerhalb der Token-/Allowlist')
    }
  }

  // Abstände/Radien: Längen mit Einheit.
  for (const length of css.match(/\b\d*\.?\d+(?:px|rem|em)\b/g) ?? []) {
    if (!tokenLengths.has(length) && !ALLOWED_LENGTHS.has(length)) {
      violations.push('Hartkodierte Länge ' + length + ' nicht in der Token-/Allowlist')
    }
  }

  return violations
}

// -----------------------------------------------------------------------------
// Menge der restyleten SFCs (Views + Komponenten inkl. admin)
// -----------------------------------------------------------------------------
interface Sfc {
  readonly name: string
  readonly path: string
}

const sfcs: readonly Sfc[] = [
  { name: 'App.vue (Inhaltsbereich)', path: resolve(srcDir, 'App.vue') },
  { name: 'SearchView.vue', path: resolve(viewsDir, 'SearchView.vue') },
  { name: 'QuoteDetailView.vue', path: resolve(viewsDir, 'QuoteDetailView.vue') },
  { name: 'SourceDetailView.vue', path: resolve(viewsDir, 'SourceDetailView.vue') },
  { name: 'AdminView.vue', path: resolve(viewsDir, 'AdminView.vue') },
  { name: 'NotFoundView.vue', path: resolve(viewsDir, 'NotFoundView.vue') },
  { name: 'AppHeader.vue', path: resolve(componentsDir, 'AppHeader.vue') },
  { name: 'AppFooter.vue', path: resolve(componentsDir, 'AppFooter.vue') },
  { name: 'ScrollToTop.vue', path: resolve(componentsDir, 'ScrollToTop.vue') },
  { name: 'LanguageSwitcher.vue', path: resolve(componentsDir, 'LanguageSwitcher.vue') },
  { name: 'LengthLimitedInput.vue', path: resolve(componentsDir, 'LengthLimitedInput.vue') },
  { name: 'admin/AdminLogin.vue', path: resolve(adminDir, 'AdminLogin.vue') },
  { name: 'admin/QuoteManager.vue', path: resolve(adminDir, 'QuoteManager.vue') },
  { name: 'admin/SourceManager.vue', path: resolve(adminDir, 'SourceManager.vue') },
  { name: 'admin/LanguageManager.vue', path: resolve(adminDir, 'LanguageManager.vue') },
  { name: 'admin/DeleteSourceDialog.vue', path: resolve(adminDir, 'DeleteSourceDialog.vue') },
]

// Einmalig kompilieren (teurer Schritt außerhalb der Property-Schleife).
const compiled: ReadonlyArray<{ sfc: Sfc; css: string }> = sfcs.map((sfc) => ({
  sfc,
  css: compileComponentCss(sfc.path),
}))

describe('Alle Gestaltungswerte stammen aus Tokens (Task 6.8)', () => {
  it('parst eine nicht-leere Token-Wertmenge aus _variables.scss', () => {
    // Belegt die Methodik: ohne Token-Mengen wäre jeder Vergleich wirkungslos.
    expect(tokenColors.size).toBeGreaterThan(0)
    expect(tokenLengths.size).toBeGreaterThan(0)
    expect(tokenRgba.size).toBeGreaterThan(0)
    // Verbindliche Palette-/Schatten-Werte müssen enthalten sein.
    expect(tokenColors.has('#282d37')).toBe(true)
    expect(tokenColors.has('#ffffff')).toBe(true)
    expect(tokenColors.has('#c15beb')).toBe(true)
    expect(tokenRgba.has(compact('rgba(0,0,0,0.4)'))).toBe(true)
  })

  it('kompiliert für jede restylete SFC eine nicht-leere CSS', () => {
    expect(compiled).toHaveLength(16)
    for (const { sfc, css } of compiled) {
      expect(css.length, sfc.name + ' ergab leere CSS').toBeGreaterThan(0)
    }
  })

  it('erkennt echte Hartkodierungen als Verstoß (interner Negativ-Check)', () => {
    // Synthetischer String — es werden KEINE echten Dateien verändert. Belegt,
    // dass der Scanner Hex-Literale außerhalb der Token-Menge (#abc123, #666)
    // sowie eine fremde rgba-Farbe und eine untokenisierte Länge findet.
    const synthetic =
      '.x { color: #abc123; border-color: #666; ' +
      'background: rgba(10, 20, 30, 0.5); gap: 13px; margin: 1rem; }'
    const violations = findHardcodedValues(synthetic)
    expect(violations.some((v) => v.includes('#abc123'))).toBe(true)
    expect(violations.some((v) => v.includes('#666'))).toBe(true)
    expect(violations.some((v) => v.includes('rgba(10, 20, 30, 0.5)'))).toBe(true)
    expect(violations.some((v) => v.includes('13px'))).toBe(true)
    // Das echte Token (1rem) darf NICHT als Verstoß gelten.
    expect(violations.some((v) => v.includes('1rem'))).toBe(false)
  })

  // Feature: ui-redesign, Property 1: Alle Gestaltungswerte stammen aus Tokens
  it('bezieht in jeder restyleten SFC alle Gestaltungswerte aus Tokens', () => {
    fc.assert(
      fc.property(fc.nat({ max: compiled.length - 1 }), (index) => {
        const { sfc, css } = compiled[index]
        const violations = findHardcodedValues(css)
        expect(
          violations,
          sfc.name + ' verwendet nicht-tokenisierte Gestaltungswerte:\n' +
            violations.join('\n'),
        ).toEqual([])
      }),
      { numRuns: 100 },
    )
  })
})
