import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'

// Task 4.7 — Beispieltest: fixiertes Header-Layout und Inhaltsabstand
// (Req 4.1, 4.3, 6.3).
//
// Verifiziert die vom Design vorgegebenen Layout-Werte der Menueleiste und des
// Inhaltsbereichs:
//   - .app-header:              position: fixed, z-index: 1000   (Req 4.1)
//   - .app-main (App.vue):      padding-top: 96px                (Req 4.3)
//   - .app-header__nav-link::after: height: 2px, Farbe loest auf #C15BEB (Req 6.3)
//
// Vorgehen (dokumentiert, analog zu den Schwester-SCSS-Tests
// contrast.test.ts / typography.test.ts):
//
// Vue `<style scoped>` wird im Vitest/jsdom-Umfeld NICHT zu angewandten Styles
// kompiliert — `getComputedStyle` auf gemounteten Komponenten liefert die
// SCSS-Regeln nicht zurueck. Daher extrahieren wir den `<style lang="scss">`-
// Block direkt aus der .vue-Quelle und kompilieren ihn mit `sass`. Die scoped-
// Attributselektoren (`[data-v-...]`) erzeugt Vite erst zur Build-Zeit; die
// hier kompilierte SCSS enthaelt die reinen Klassenselektoren, was fuer die
// Pruefung der deklarierten Layout-Werte ausreicht.
//
// Die `@use '../scss/variables'`- bzw. `@use '../scss/mixins'`-Pfade im
// scoped Block sind relativ zur Komponentendatei. Wir kompilieren den Block
// deshalb mit `compileString` und setzen `loadPaths` auf das Komponenten-
// Verzeichnis (fuer `../scss/...`) sowie das scss-Verzeichnis. Die Pfad-
// aufloesung erfolgt — wie in den Schwester-Tests — ueber `process.cwd()`
// (Vitest nutzt das frontend-Paket als cwd).

const frontendRoot = process.cwd()
const componentsDir = resolve(frontendRoot, 'src/components')
const scssDir = resolve(frontendRoot, 'src/scss')

/** Extrahiert den Inhalt des ersten `<style ... lang="scss" ...>`-Blocks einer SFC. */
function extractScopedScss(sfcPath: string): string {
  const source = readFileSync(sfcPath, 'utf8')
  const match = source.match(/<style[^>]*lang="scss"[^>]*>([\s\S]*?)<\/style>/)
  if (!match) {
    throw new Error('kein <style lang="scss">-Block in ' + sfcPath + ' gefunden')
  }
  return match[1]
}

const srcDir = resolve(frontendRoot, 'src')

/**
 * Kompiliert eine scoped-SCSS-Quelle zu CSS.
 *
 * Die SFCs referenzieren die Token-/Mixin-Stylesheets ueber zwei Schreibweisen:
 * komponentenrelativ (`@use '../scss/variables'`, AppHeader.vue) und ueber den
 * Vite-Alias `@/` (`@use '@/scss/variables'`, App.vue). `sass` kennt den Alias
 * nicht, daher wird das `@/`-Praefix vor dem Kompilieren auf einen gegen `src/`
 * aufloesbaren Pfad reduziert; `loadPaths` deckt sowohl `src/` (fuer die
 * ehemaligen `@/`-Pfade) als auch das Komponenten- und scss-Verzeichnis ab.
 */
function compileScoped(scss: string): string {
  const withoutAlias = scss.replace(/(@use\s+['"])@\//g, '$1')
  return sass.compileString(withoutAlias, {
    loadPaths: [componentsDir, scssDir, srcDir],
  }).css
}

/** Isoliert den Deklarationsblock einer Regel mit exakt passendem Selektor. */
function ruleBlock(css: string, selector: string): string {
  // Selektor-Sonderzeichen (., ::, etc.) fuer die Regex maskieren; umgebende
  // Grenze (Zeilenanfang / schliessende Klammer) verhindert Teiltreffer.
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
  const match = block.match(new RegExp('(?:^|[;{]|\\s)' + property + '\\s*:\\s*([^;]+);'))
  if (!match) {
    throw new Error('Eigenschaft "' + property + '" nicht im Regelblock deklariert')
  }
  return match[1].trim()
}

// --- Token-Quelle: --color-primary aus dem kompilierten :root von _variables ---
// Die Unterstrich-Farbe wird im scoped Block ueber var(--color-primary, ...)
// referenziert. Wir lesen den Zielwert aus der zentralen Token-Definition,
// statt ihn als Literal zu duplizieren (einzige Quelle der Wahrheit).
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

/** Loest einen `var(--token[, fallback])`-Ausdruck gegen die :root-Tokens auf. */
function resolveVar(expr: string): string {
  const varMatch = expr.match(/var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)/)
  if (!varMatch) {
    return expr
  }
  return rootToken(varMatch[1].slice(2))
}

const headerCss = compileScoped(extractScopedScss(resolve(componentsDir, 'AppHeader.vue')))
const appCss = compileScoped(
  extractScopedScss(resolve(dirname(componentsDir), 'App.vue')),
)

describe('AppHeader/App Layout (Task 4.7)', () => {
  it('fixiert die Menueleiste am oberen Rand: position fixed, z-index 1000 (Req 4.1)', () => {
    const block = ruleBlock(headerCss, '.app-header')
    expect(declaration(block, 'position')).toBe('fixed')
    expect(declaration(block, 'z-index')).toBe('1000')
  })

  it('setzt den Inhaltsabstand unter der fixierten Leiste: padding-top 96px (Req 4.3)', () => {
    const block = ruleBlock(appCss, '.app-main')
    expect(declaration(block, 'padding-top')).toBe('96px')
  })

  it('zeichnet den aktiven Nav-Unterstrich 2px in #C15BEB (Req 6.3)', () => {
    const block = ruleBlock(headerCss, '.app-header__nav-link::after')
    expect(declaration(block, 'height')).toBe('2px')

    const colorExpr = declaration(block, 'background-color')
    // Die Farbe wird als var(--color-primary, #C15BEB) deklariert; sie loest
    // ueber die zentrale Token-Definition auf #C15BEB auf.
    expect(resolveVar(colorExpr).toLowerCase()).toBe('#c15beb')
  })
})
