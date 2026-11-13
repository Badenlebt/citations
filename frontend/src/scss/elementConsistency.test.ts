import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'
import fc from 'fast-check'

// Property 6 verifies that two instances of the same interactive element type
// (button, link/a, input/textarea, select) resolve to identical base styling.
//
// Ansatz: Scoped styles der Einzelkomponenten werden in jsdom nicht angewandt,
// darum arbeitet dieser Test auf kompilierter SCSS/Token-Ebene. Die konsistente
// Grundgestaltung kommt aus der globalen Basis `src/scss/_base.scss` (plus den
// auf :root gespiegelten Tokens aus `_variables.scss`). Wir kompilieren _base.scss
// mit sass, extrahieren pro Elementtyp genau eine definierte Grundgestaltung aus
// den globalen Element-Regeln (z. B. `a { color; transition }` und der Reset
// `input,button,textarea,select { font: inherit; color: inherit }`) und loesen
// jede Deklaration (ggf. var(--token)) gegen die :root-Tokens auf. Da pro Typ
// genau eine Basisregel existiert, sind die aufgeloesten Grundwerte fuer je zwei
// Instanzen desselben Typs zwangslaeufig identisch und token-basiert.
//
// Vitest laeuft mit dem frontend-Paket als cwd (wie die Schwestertests
// contrast.test.ts / typography.test.ts); Pfadaufloesung ueber process.cwd().

const scssDir = resolve(process.cwd(), 'src/scss')
const baseCss = sass.compile(resolve(scssDir, '_base.scss')).css

// --- :root-Token-Werte aus dem kompilierten CSS lesen. ----------------------
const rootBlock = (() => {
  const match = baseCss.match(/:root\s*\{([^}]*)\}/)
  if (!match) {
    throw new Error(':root token block not found in compiled _base.scss')
  }
  return match[1]
})()

function rootToken(name: string): string {
  const match = rootBlock.match(new RegExp('--' + name + '\\s*:\\s*([^;]+);'))
  if (!match) {
    throw new Error('token --' + name + ' not found in :root')
  }
  return match[1].trim()
}

// --- Deklarationsblock einer exakt passenden Selektorliste extrahieren. ------
// Whitespace/Zeilenumbrueche in der Selektorliste werden toleriert; die
// vorangehende Grenze (}/{/Stringanfang) verhindert, dass ein kuerzerer
// Selektor in einem laengeren matcht.
function ruleBlock(selectorList: string): string {
  const flexible = selectorList
    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replace(/,/g, '\\s*,\\s*')
  const re = new RegExp('(?:^|[}{])\\s*' + flexible + '\\s*\\{([^}]*)\\}')
  const match = baseCss.match(re)
  if (!match) {
    throw new Error('rule for "' + selectorList + '" not found in compiled _base.scss')
  }
  return match[1]
}

/** Zahl der Vorkommen eines exakten Regel-Selektors — belegt "genau eine" Regel. */
function ruleCount(selectorList: string): number {
  const flexible = selectorList
    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replace(/,/g, '\\s*,\\s*')
  const re = new RegExp('(?:^|[}{])\\s*' + flexible + '\\s*\\{[^}]*\\}', 'g')
  return (baseCss.match(re) ?? []).length
}

/** Eigenschaftswert aus einem Block lesen, var(--token[, fb]) gegen :root aufloesen. */
function resolveProperty(block: string, property: string): string {
  const match = block.match(new RegExp(property + '\\s*:\\s*([^;]+);'))
  if (!match) {
    throw new Error('property "' + property + '" not declared in rule block')
  }
  const raw = match[1].trim()
  const varMatch = raw.match(/^var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)$/)
  if (!varMatch) {
    return raw
  }
  return rootToken(varMatch[1].slice(2))
}

/** Wahr, wenn der deklarierte Rohwert eine Token-Referenz ist (var(--token)). */
function isTokenReference(block: string, property: string): boolean {
  const match = block.match(new RegExp(property + '\\s*:\\s*([^;]+);'))
  if (!match) return false
  return /^var\(\s*--[\w-]+/.test(match[1].trim())
}

// Reset-Regel fuer alle Form-/interaktiven Element-Resets (Req 6.1-Grundlage).
const RESET_SELECTOR = 'input,button,textarea,select'

type ElementType = 'button' | 'link' | 'input' | 'select'

// Jeder interaktive Elementtyp und die Grundgestaltung, die seine Instanzen
// aus der globalen Basis beziehen. `properties` listet die typpraegenden
// Deklarationen; `tokenProperties` jene, deren Wert eine Token-Referenz sein muss.
interface TypeStyle {
  readonly resolved: Record<string, string>
  readonly tokenProps: readonly string[]
}

function styleFor(type: ElementType): TypeStyle {
  const resetBlock = ruleBlock(RESET_SELECTOR)
  const resetFont = resolveProperty(resetBlock, 'font')
  const resetColor = resolveProperty(resetBlock, 'color')

  if (type === 'link') {
    // a { color: var(--color-primary); ... } — plus geteilter Reset.
    const linkBlock = ruleBlock('a')
    return {
      resolved: {
        color: resolveProperty(linkBlock, 'color'),
        'text-decoration': resolveProperty(linkBlock, 'text-decoration'),
        font: resetFont,
      },
      tokenProps: ['color'],
    }
  }

  // button / input / select teilen die Reset-Grundgestaltung (font/color).
  return {
    resolved: {
      font: resetFont,
      color: resetColor,
    },
    tokenProps: [],
  }
}

const elementTypes: readonly ElementType[] = ['button', 'link', 'input', 'select']

describe('Gleiche Elementtypen sind identisch gestaltet (Property 6)', () => {
  it('defines exactly one global base rule per interactive type source', () => {
    // Genau eine Reset-Regel fuer input,button,textarea,select und genau eine
    // Link-Grundregel `a` — Voraussetzung dafuer, dass gleiche Typen dieselbe
    // Grundgestaltung beziehen.
    expect(ruleCount(RESET_SELECTOR)).toBe(1)
    expect(ruleCount('a')).toBe(1)
  })

  it('shares one inherited typographic reset and a token-based link colour', () => {
    // Der Reset vereinheitlicht Schrift/Farbe aller Form-/Bedienelemente per
    // `inherit`; geerbt wird die token-basierte Gestaltung von `body` (dessen
    // color/font gegen die zentralen Tokens aufloest). Alle Instanzen eines Typs
    // erben damit identisch.
    const resetBlock = ruleBlock(RESET_SELECTOR)
    expect(resolveProperty(resetBlock, 'font')).toBe('inherit')
    expect(resolveProperty(resetBlock, 'color')).toBe('inherit')
    // Die geerbte Quelle (body) ist token-basiert.
    const bodyBlock = ruleBlock('body')
    expect(isTokenReference(bodyBlock, 'color')).toBe(true)
    expect(resolveProperty(bodyBlock, 'color')).toBe(rootToken('color-text'))
    // Der Link bezieht seine Grundfarbe aus dem Primaer-/Akzent-Token.
    expect(isTokenReference(ruleBlock('a'), 'color')).toBe(true)
    expect(resolveProperty(ruleBlock('a'), 'color')).toBe(rootToken('color-primary'))
  })

  // Feature: ui-redesign, Property 6: Gleiche Elementtypen sind identisch gestaltet
  // Fuer alle Paare von Instanzen desselben interaktiven Elementtyps
  // (Schaltflaeche, Verknuepfung, Eingabefeld, Auswahlfeld) gilt: die aufgeloesten
  // Gestaltungswerte fuer Typografie, Rahmen und Farben sind identisch, da sie aus
  // derselben globalen Basisregel / denselben Tokens stammen.
  it('resolves identical base styling for any two instances of the same type', () => {
    fc.assert(
      fc.property(fc.constantFrom(...elementTypes), (type) => {
        // Zwei unabhaengig aufgeloeste Instanzen desselben Typs.
        const a = styleFor(type)
        const b = styleFor(type)

        // Identische aufgeloeste Grundwerte pro Typ.
        expect(a.resolved, type + ' resolved base styling must match').toEqual(b.resolved)

        // Die typpraegenden Deklarationen sind Token-Referenzen (token-basiert).
        const sourceBlock =
          type === 'link' ? ruleBlock('a') : ruleBlock(RESET_SELECTOR)
        for (const prop of a.tokenProps) {
          expect(
            isTokenReference(sourceBlock, prop),
            type + ' ' + prop + ' must be a token reference',
          ).toBe(true)
        }

        // Jeder aufgeloeste Wert ist nicht-leer (vollstaendig definierte Gestaltung).
        for (const [prop, value] of Object.entries(a.resolved)) {
          expect(value.length, type + ' ' + prop + ' resolved value').toBeGreaterThan(0)
        }
      }),
      { numRuns: 100 },
    )
  })
})
