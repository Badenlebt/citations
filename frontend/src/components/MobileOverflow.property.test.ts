import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import * as sass from 'sass'

// =============================================================================
// Task 8.2 — Property-Test: Kein horizontaler Überlauf im Mobilbereich
//
// Feature: ui-redesign, Property 8: Kein horizontaler Überlauf im Mobilbereich
//
// Design (design.md, Property 8): *Für alle* Viewport-Breiten im Bereich von
// 320px bis unter 768px gilt: der Inhalt erzeugt kein horizontales Scrollen
// (`scrollWidth <= clientWidth`), und alle interaktiven Elemente liegen
// vollständig innerhalb der Viewport-Breite.
//
// Validates: Requirements 7.4
// -----------------------------------------------------------------------------
//
// jsdom-Einschränkung (ausführlich dokumentiert, da sie die Testmethodik
// bestimmt):
//
// Vitest läuft hier gegen jsdom. jsdom implementiert KEINE Layout-Engine: Es
// berechnet weder Boxgrößen noch Zeilenumbrüche. Konkret bedeutet das für
// einen naiven DOM-Overflow-Test:
//   - `element.scrollWidth`, `element.clientWidth`, `element.offsetWidth`
//     liefern konstant 0 (kein Layout → keine Maße). Eine Prüfung
//     `scrollWidth <= clientWidth` wäre damit immer 0 <= 0 und würde JEDE
//     Implementierung bestehen lassen — auch eine mit festen 2000px-Breiten.
//   - `getBoundingClientRect()` liefert für alle Kanten 0.
//   - `getComputedStyle()` gibt zwar deklarierte Inline-/Stylesheet-Werte
//     zurück, kann aber keine aufgelöste Pixelbreite aus `width: 100%`,
//     Grid-Spalten oder Media-Queries berechnen.
//   - Vue `<style scoped>` wird im Vitest/jsdom-Umfeld gar nicht erst zu
//     angewandten Styles kompiliert (die `[data-v-…]`-Attributselektoren und
//     das SCSS→CSS-Transpiling entstehen erst im Vite-Build).
// Ein gemounteter DOM-Overflow-Test ist in dieser Umgebung also nicht
// aussagekräftig — er wäre ein Scheintest.
//
// Gewählter Ansatz (robuste CSS-Quellen-Prüfung, konsistent mit den
// Schwester-SCSS-Tests contrast.test.ts / AppFooter.layout.test.ts /
// AppHeader.layout.test.ts):
//
// Overflow im Mobilbereich entsteht genau dann, wenn ein Layout-Baustein eine
// Breite deklariert, die die Viewport-Breite überschreiten kann — typischerweise
// durch eine feste px-Breite größer als der Viewport, durch ein nicht in den
// Container eingerechnetes Padding (fehlendes `box-sizing: border-box`) oder
// durch unumbrechbare Spalten. Umgekehrt ist Überlauf ausgeschlossen, wenn die
// geprüften Container ausschließlich viewport-relative bzw. begrenzende
// Deklarationen verwenden. Wir kompilieren deshalb die realen CSS-Quellen mit
// `sass` und weisen für jede Kombination aus (a) einer Viewport-Breite im
// Bereich 320..<768 und (b) einem geprüften Layout-Container nach, dass dessen
// deklarierte Breiten/Constraints den Viewport nicht überschreiten:
//   - `.container` (_utilities.scss): `width: 100%` + `max-width: 1200px`
//     (deckelt nur bei größeren Viewports) + `margin-inline: auto`. 100% einer
//     320..<768-Box ist stets <= Viewport → kein Überlauf.
//   - `.app-footer__grid` (AppFooter.vue): mobil `repeat(2, 1fr)` — fraktionale
//     Spalten, die sich den verfügbaren Platz teilen; KEINE feste px-Breite.
//   - `.length-limited-input__field` (LengthLimitedInput.vue): `width: 100%`
//     zusammen mit `box-sizing: border-box`, sodass Padding/Border in die 100%
//     eingerechnet werden und das Feld nicht über den Container hinauswächst.
//   - `.app-header__actions` (AppHeader.vue): unterhalb md als Slide-in-Panel
//     mit `position: absolute; left: 0; right: 0` (an den Viewport gebunden) und
//     vertikaler Stapelung (`flex-direction: column`) statt horizontalem
//     Überlauf. AppHeader.vue deklariert MEHRERE `.app-header__actions`-Regeln
//     (horizontale Basisregel ohne left/right, dieses mobile Panel und — in
//     `@media (min-width: 768px)` — eine `position: static`-Zurücksetzung).
//     Der Test sammelt alle media-query-freien Blöcke und wählt gezielt den
//     mobilen Panel-Block (`position: absolute`), nicht den ersten Treffer.
// Zusätzlich wird für jeden geprüften Container global ausgeschlossen, dass er
// eine feste px-Breite deklariert, die größer als die generierte Viewport-
// Breite ist (keine harten px-Breiten > Viewport).
//
// Pfadauflösung erfolgt — wie in den Schwester-Tests — über `process.cwd()`
// (Vitest nutzt das frontend-Paket als cwd).
// =============================================================================

const frontendRoot = process.cwd()
const scssDir = resolve(frontendRoot, 'src/scss')
const componentsDir = resolve(frontendRoot, 'src/components')
const utilitiesPath = resolve(scssDir, '_utilities.scss')
const appFooterPath = resolve(componentsDir, 'AppFooter.vue')
const appHeaderPath = resolve(componentsDir, 'AppHeader.vue')
const lengthInputPath = resolve(componentsDir, 'LengthLimitedInput.vue')

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
 * Kompiliert eine scoped-SCSS-Quelle zu CSS. Die SFCs referenzieren die Token-/
 * Mixin-Stylesheets komponentenrelativ (`@use '../scss/…'`) oder über den
 * Vite-Alias `@/` (`@use '@/scss/…'`). `sass` kennt den Alias nicht, daher wird
 * das `@/`-Präfix vor dem Kompilieren entfernt; `loadPaths` deckt die relevanten
 * Verzeichnisse ab, sodass die Imports exakt wie im Vite-Build auflösen.
 */
function compileScoped(sfcPath: string): string {
  const scss = extractScopedScss(sfcPath).replace(/(@use\s+['"])@\//g, '$1')
  return sass.compileString(scss, {
    url: pathToFileURL(sfcPath),
    loadPaths: [componentsDir, scssDir, resolve(frontendRoot, 'src')],
  }).css
}

/** Isoliert den Deklarationsblock einer Top-Level-Regel mit passendem Selektor. */
function ruleBlock(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const re = new RegExp('(?:^|[}{])\\s*' + escaped + '\\s*\\{([^}]*)\\}')
  const match = css.match(re)
  if (!match) {
    throw new Error('Regel fuer "' + selector + '" nicht in kompilierter CSS gefunden')
  }
  return match[1]
}

/**
 * Berechnet die Zeichenbereiche aller Top-Level-`@media`-Bloecke in der
 * kompilierten CSS. Mit diesen Bereichen lassen sich Regeltreffer, die INNERHALB
 * einer Media-Query stehen, gezielt ausschliessen — notwendig, weil die mobile
 * (media-query-freie) Deklaration von der `@media (min-width: 768px)`-Variante
 * desselben Selektors getrennt werden muss.
 */
function mediaRanges(css: string): ReadonlyArray<readonly [number, number]> {
  const ranges: Array<readonly [number, number]> = []
  const atMedia = /@media[^{]*\{/g
  let match: RegExpExecArray | null
  while ((match = atMedia.exec(css)) !== null) {
    // Ab der oeffnenden Klammer der Media-Query die passende schliessende
    // Klammer per Tiefenzaehlung finden (Media-Bloecke enthalten verschachtelte
    // Regel-Klammern).
    let depth = 1
    let i = atMedia.lastIndex
    for (; i < css.length && depth > 0; i++) {
      if (css[i] === '{') depth++
      else if (css[i] === '}') depth--
    }
    ranges.push([match.index, i] as const)
  }
  return ranges
}

/**
 * Sammelt die Deklarationsbloecke ALLER Top-Level-Regeln (ausserhalb jeder
 * `@media`-Query), deren Selektor exakt `selector` ist — also nicht als Teil
 * eines Nachfahren-/Kompositselektors wie `.x .y` oder `.z .x`. In der
 * kompilierten CSS erscheint `.app-header__actions` mehrfach: als horizontale
 * Basisregel (ohne left/right), als mobiles Slide-in-Panel (position: absolute)
 * und — innerhalb `@media (min-width: 768px)` — als statische Zuruecksetzung.
 * Diese Funktion liefert nur die media-query-freien Treffer, aus denen der
 * Aufrufer den gesuchten Block per Praedikat waehlt.
 */
function topLevelRuleBlocks(css: string, selector: string): string[] {
  const ranges = mediaRanges(css)
  const inMedia = (position: number): boolean =>
    ranges.some(([start, end]) => position >= start && position < end)

  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  // Der Selektor muss direkt am Blockanfang stehen (nach `}`, `{` oder Dateianfang)
  // und unmittelbar von `{` gefolgt sein, damit Nachfahrenselektoren
  // (`.app-header__actions .app-header__nav`) und vorangestellte Kombinatoren
  // (`.app-header--menu-open .app-header__actions`) nicht faelschlich greifen.
  const re = new RegExp('(?:^|[}{])\\s*' + escaped + '\\s*\\{([^}]*)\\}', 'g')
  const blocks: string[] = []
  let match: RegExpExecArray | null
  while ((match = re.exec(css)) !== null) {
    // Treffer, die innerhalb einer Media-Query liegen, verwerfen.
    if (!inMedia(match.index)) {
      blocks.push(match[1])
    }
  }
  return blocks
}

/**
 * Waehlt aus allen media-query-freien `.app-header__actions`-Bloecken gezielt
 * den mobilen Slide-in-Panel-Block: die Regel mit `position: absolute` (an den
 * Viewport gebundenes Panel mit left: 0 / right: 0). Nicht der erste Treffer
 * (die horizontale Basisregel ohne left/right) und nicht die statische
 * Zuruecksetzung aus `@media (min-width: 768px)`.
 */
function mobileActionsBlock(css: string): string {
  const blocks = topLevelRuleBlocks(css, '.app-header__actions')
  const panel = blocks.find(
    (block) => normalise(readProperty(block, 'position') ?? '') === 'absolute',
  )
  if (!panel) {
    throw new Error(
      'mobiler .app-header__actions-Block (position: absolute) nicht in ' +
        'kompilierter CSS gefunden',
    )
  }
  return panel
}

/** Liest einen Eigenschaftswert aus einem Deklarationsblock (oder null). */
function readProperty(block: string, property: string): string | null {
  const re = new RegExp(property.replace(/-/g, '\\-') + '\\s*:\\s*([^;]+);')
  const match = block.match(re)
  return match ? match[1].trim() : null
}

/** Collabiert Whitespace, damit `repeat(2,  1fr)` sauber vergleichbar ist. */
function normalise(value: string): string {
  return value.replace(/\s+/g, ' ').trim()
}

// -----------------------------------------------------------------------------
// Kompilierte CSS-Quellen (einmalig) + die geprüften Layout-Container.
// -----------------------------------------------------------------------------
const utilitiesCss = sass.compile(utilitiesPath).css
const footerCss = compileScoped(appFooterPath)
const headerCss = compileScoped(appHeaderPath)
const inputCss = compileScoped(lengthInputPath)

interface Container {
  /** Sprechender Name für Fehlermeldungen. */
  readonly name: string
  /** Deklarationsblock der zu prüfenden Regel. */
  readonly block: string
  /**
   * Prüft, dass die deklarierten Breiten/Constraints eine gegebene Viewport-
   * Breite nicht überschreiten. Rückgabe: Beschreibung des Verstoßes oder null,
   * wenn kein Überlauf möglich ist.
   */
  readonly overflowReason: (viewport: number) => string | null
}

/**
 * Fängt feste px-Breiten ab, die größer als der Viewport sind. Fraktionale
 * (fr), prozentuale (%), viewport-relative (vw) und auto/max-content-Werte
 * können keinen festen Überlauf erzeugen und gelten als sicher.
 */
function fixedPxWidthOverflow(block: string, viewport: number): string | null {
  const width = readProperty(block, 'width')
  if (width && /^\d+(?:\.\d+)?px$/.test(width)) {
    const px = parseFloat(width)
    if (px > viewport) {
      return `feste width ${width} > Viewport ${viewport}px`
    }
  }
  const minWidth = readProperty(block, 'min-width')
  if (minWidth && /^\d+(?:\.\d+)?px$/.test(minWidth)) {
    const px = parseFloat(minWidth)
    if (px > viewport) {
      return `feste min-width ${minWidth} > Viewport ${viewport}px`
    }
  }
  return null
}

const containers: readonly Container[] = [
  {
    // .container: width:100% (nie > Viewport) + max-width deckelt nur größere
    // Viewports + horizontale Zentrierung. Kein Überlauf im Mobilbereich.
    name: '.container (Inhaltsbereich)',
    block: ruleBlock(utilitiesCss, '.container'),
    overflowReason: (viewport) => {
      const block = ruleBlock(utilitiesCss, '.container')
      if (normalise(readProperty(block, 'width') ?? '') !== '100%') {
        return '.container deklariert nicht width: 100%'
      }
      return fixedPxWidthOverflow(block, viewport)
    },
  },
  {
    // .app-footer__grid: mobil repeat(2, 1fr) — fraktionale Spalten teilen sich
    // den Platz; keine feste px-Breite, daher kein Überlauf.
    name: '.app-footer__grid (Footer, mobil)',
    block: ruleBlock(footerCss, '.app-footer__grid'),
    overflowReason: (viewport) => {
      const block = ruleBlock(footerCss, '.app-footer__grid')
      const columns = normalise(readProperty(block, 'grid-template-columns') ?? '')
      if (columns !== 'repeat(2, 1fr)') {
        return `.app-footer__grid mobil erwartet 'repeat(2, 1fr)', war '${columns}'`
      }
      return fixedPxWidthOverflow(block, viewport)
    },
  },
  {
    // .length-limited-input__field: width:100% + box-sizing:border-box rechnet
    // Padding/Border in die Breite ein → wächst nicht über den Container hinaus.
    name: '.length-limited-input__field (Formularfeld)',
    block: ruleBlock(inputCss, '.length-limited-input__field'),
    overflowReason: (viewport) => {
      const block = ruleBlock(inputCss, '.length-limited-input__field')
      if (normalise(readProperty(block, 'width') ?? '') !== '100%') {
        return 'Formularfeld deklariert nicht width: 100%'
      }
      if (normalise(readProperty(block, 'box-sizing') ?? '') !== 'border-box') {
        return 'Formularfeld deklariert nicht box-sizing: border-box'
      }
      return fixedPxWidthOverflow(block, viewport)
    },
  },
  {
    // .app-header__actions: unterhalb md ein Slide-in-Panel mit left:0;right:0
    // (an den Viewport gebunden) und vertikaler Stapelung statt horizontalem
    // Überlauf. Es gibt MEHRERE .app-header__actions-Regeln (horizontale
    // Basisregel ohne left/right, mobiles position:absolute-Panel und — in
    // @media (min-width: 768px) — die position:static-Zuruecksetzung). Geprüft
    // wird gezielt der mobile Panel-Block (position: absolute), nicht der erste
    // Treffer und nicht die Media-Query-Variante.
    name: '.app-header__actions (Navigation, mobil)',
    block: mobileActionsBlock(headerCss),
    overflowReason: (viewport) => {
      const block = mobileActionsBlock(headerCss)
      if (normalise(readProperty(block, 'position') ?? '') !== 'absolute') {
        return 'mobiles Navigationspanel deklariert nicht position: absolute'
      }
      if (normalise(readProperty(block, 'left') ?? '') !== '0') {
        return 'mobiles Navigationspanel deklariert nicht left: 0'
      }
      if (normalise(readProperty(block, 'right') ?? '') !== '0') {
        return 'mobiles Navigationspanel deklariert nicht right: 0'
      }
      if (normalise(readProperty(block, 'flex-direction') ?? '') !== 'column') {
        return 'mobiles Navigationspanel stapelt nicht vertikal (flex-direction: column)'
      }
      return fixedPxWidthOverflow(block, viewport)
    },
  },
]

describe('Mobile viewport: kein horizontaler Überlauf (Task 8.2)', () => {
  // Vorab-Belege, dass die kompilierten Quellen die erwarteten Container tragen
  // (fängt Fehlkonfiguration der Methodik, nicht nur die Property, ab).
  it('kompiliert die geprüften Layout-Container aus den realen Quellen', () => {
    expect(containers).toHaveLength(4)
    for (const container of containers) {
      expect(container.block.length).toBeGreaterThan(0)
    }
  })

  // Feature: ui-redesign, Property 8: Kein horizontaler Überlauf im Mobilbereich
  it('hält alle Layout-Container für Viewport-Breiten 320..<768 innerhalb der Breite', () => {
    fc.assert(
      fc.property(
        // Viewport-Breiten im Bereich 320px bis unter 768px.
        fc.integer({ min: 320, max: 767 }),
        // Index in die Container-Liste (fast-check generiert über beide Achsen).
        fc.nat({ max: containers.length - 1 }),
        (viewport, index) => {
          const container = containers[index]
          const reason = container.overflowReason(viewport)
          expect(
            reason,
            `${container.name} erzeugt bei Viewport ${viewport}px horizontalen ` +
              `Überlauf: ${reason}`,
          ).toBeNull()
        },
      ),
      { numRuns: 100 },
    )
  })
})
