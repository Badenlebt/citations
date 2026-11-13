import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'
import fc from 'fast-check'

// Property 5 verifies that every rendered text element resolves to the
// typography values defined by the design tokens. Both the element rules
// (_base.scss) and the token definitions (_variables.scss) are read from the
// single source of truth and compiled with sass, so the expected values are
// never duplicated as literals here — they come straight from the tokens.
//
// _base.scss styles elements via CSS custom properties (e.g.
// `font-size: var(--font-size-h1, ...)`). We compile the SCSS, read the
// `:root` custom-property values, and resolve each element's declared
// `var(--token)` reference back to that value. The resolved value is what a
// browser applies to the rendered element, so comparing it with the token
// definition proves the typography is resolved type-correctly.

// Vitest runs with the frontend package root as cwd (same as the sibling
// contrast/statusColors tests); resolve the token sources from there.
const scssDir = resolve(process.cwd(), 'src/scss')

const baseCss = sass.compile(resolve(scssDir, '_base.scss')).css

// --- Read the :root custom-property token values from the compiled CSS. ------
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

// --- Read an element's declared property and resolve any var() reference. ----
/**
 * Extract the declaration block for a rule whose selector list matches exactly
 * `selectorList` (a single selector such as `body`, or a grouped list such as
 * `h1,h2,...`). Whitespace/newlines inside the selector list are tolerated,
 * and the preceding `}`/`{`/string-start boundary prevents matching a longer
 * selector (so `h1` never matches inside `html`, and a single `h6` never
 * matches the grouped heading rule).
 */
function ruleBlock(selectorList: string): string {
  const flexible = selectorList.replace(/,/g, '\\s*,\\s*')
  const re = new RegExp('(?:^|[}{])\\s*' + flexible + '\\s*\\{([^}]*)\\}')
  const match = baseCss.match(re)
  if (!match) {
    throw new Error('rule for "' + selectorList + '" not found in compiled _base.scss')
  }
  return match[1]
}

/** Read a property value from a declaration block, resolving var(--token[, fb]). */
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

// Headings share one grouped rule (h1,h2,h3,h4,h5,h6) for family/weight/
// line-height; the per-level font-size lives in its own `hN { font-size }`.
const headingGroupBlock = ruleBlock('h1,h2,h3,h4,h5,h6')
const bodyBlock = ruleBlock('body')

interface Expectation {
  readonly fontFamily: string
  readonly fontSize: string
  readonly lineHeight: string
  readonly fontWeight: string
}

type TextType = 'body' | 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6'

// Body inherits the UA default font-weight; only headings set a weight token.
const BODY_DEFAULT_WEIGHT = 'normal'

/** The resolved typography a given element type actually presents. */
function resolvedFor(type: TextType): Expectation {
  if (type === 'body') {
    return {
      fontFamily: resolveProperty(bodyBlock, 'font-family'),
      fontSize: resolveProperty(bodyBlock, 'font-size'),
      lineHeight: resolveProperty(bodyBlock, 'line-height'),
      fontWeight: BODY_DEFAULT_WEIGHT,
    }
  }
  return {
    fontFamily: resolveProperty(headingGroupBlock, 'font-family'),
    fontSize: resolveProperty(ruleBlock(type), 'font-size'),
    lineHeight: resolveProperty(headingGroupBlock, 'line-height'),
    fontWeight: resolveProperty(headingGroupBlock, 'font-weight'),
  }
}

/** The token-defined target values, read independently from the :root tokens. */
function expectedFor(type: TextType): Expectation {
  if (type === 'body') {
    return {
      fontFamily: rootToken('font-family-base'),
      fontSize: rootToken('font-size-base'),
      lineHeight: rootToken('line-height-body'),
      fontWeight: BODY_DEFAULT_WEIGHT,
    }
  }
  return {
    fontFamily: rootToken('font-family-base'),
    fontSize: rootToken('font-size-' + type),
    lineHeight: rootToken('line-height-heading'),
    fontWeight: rootToken('font-weight-heading'),
  }
}

const textTypes: readonly TextType[] = ['body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']

describe('Typografie (Property 5)', () => {
  it('reads a distinct token-defined size for each heading level and body', () => {
    const sizes = textTypes.map((t) => expectedFor(t).fontSize)
    // Each of the six headings plus body carries its own size token, so no two
    // resolve to the same value. Guards the token wiring the property relies on.
    expect(new Set(sizes).size).toBe(textTypes.length)
  })

  // Feature: ui-redesign, Property 5: Typografie wird typgerecht aufgeloest
  // Fuer alle gerenderten Textelemente gilt: die aufgeloeste Schriftfamilie,
  // Schriftgroesse, Zeilenhoehe und das Schriftgewicht entsprechen den fuer
  // den jeweiligen Typ (H1-H6 oder Fliesstext) definierten Token-Werten.
  it('resolves family, size, line-height and weight to the type tokens', () => {
    fc.assert(
      fc.property(fc.constantFrom(...textTypes), (type) => {
        const resolved = resolvedFor(type)
        const expected = expectedFor(type)
        expect(resolved.fontFamily, type + ' font-family').toBe(expected.fontFamily)
        expect(resolved.fontSize, type + ' font-size').toBe(expected.fontSize)
        expect(resolved.lineHeight, type + ' line-height').toBe(expected.lineHeight)
        expect(resolved.fontWeight, type + ' font-weight').toBe(expected.fontWeight)
      }),
      { numRuns: 100 },
    )
  })
})
