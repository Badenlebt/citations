import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'

// Integration test for the font configuration (Req 3.5).
//
// The font setup is a declarative CSS solution: if 'Nunito' loads slowly or not
// at all, `font-display: swap` makes the browser render the fallback family
// immediately, and the `font-family` chain ends on the generic `sans-serif`
// family so text never fails to display. This test proves both facts against
// the single source of truth by compiling _base.scss with sass (the sibling
// contrast/typography tests follow the same approach) and inspecting the
// produced CSS — no design values are duplicated as literals here.
//
// Vitest runs with the frontend package root as cwd (same as the sibling
// contrast/typography tests); resolve the token sources from there.
const scssDir = resolve(process.cwd(), 'src/scss')

const baseCss = sass.compile(resolve(scssDir, '_base.scss')).css

/** Extract the declaration block of the first rule matching `selector`. */
function ruleBlock(selector: string): string {
  const flexible = selector.replace(/,/g, '\\s*,\\s*')
  const re = new RegExp('(?:^|[}{])\\s*' + flexible + '\\s*\\{([^}]*)\\}')
  const match = baseCss.match(re)
  if (!match) {
    throw new Error('rule for "' + selector + '" not found in compiled _base.scss')
  }
  return match[1]
}

/** Read a `--name: value;` custom property from the compiled :root block. */
function rootToken(name: string): string {
  const rootBlockMatch = baseCss.match(/:root\s*\{([^}]*)\}/)
  if (!rootBlockMatch) {
    throw new Error(':root token block not found in compiled _base.scss')
  }
  const match = rootBlockMatch[1].match(new RegExp('--' + name + '\\s*:\\s*([^;]+);'))
  if (!match) {
    throw new Error('token --' + name + ' not found in :root')
  }
  return match[1].trim()
}

describe('Font-Konfiguration (Req 3.5)', () => {
  // The @font-face declaration must opt into swap behaviour so a delayed or
  // failed Nunito load falls back to the substitute font immediately.
  it('declares font-display: swap inside the @font-face block', () => {
    const fontFaceMatch = baseCss.match(/@font-face\s*\{([^}]*)\}/)
    expect(fontFaceMatch, '@font-face block not found in compiled _base.scss').not.toBeNull()
    const fontFaceBlock = fontFaceMatch![1]
    expect(fontFaceBlock).toMatch(/font-display\s*:\s*swap\s*;/)
  })

  // The body font-family resolves via var(--font-family-base). The token chain
  // must end on the generic `sans-serif` family (Req 3.5) so text stays visible
  // even when no custom/preferred font is available.
  it('resolves the body font-family chain to one ending on sans-serif', () => {
    const familyDecl = ruleBlock('body').match(/font-family\s*:\s*([^;]+);/)
    expect(familyDecl, 'body font-family not declared').not.toBeNull()

    const raw = familyDecl![1].trim()
    const varMatch = raw.match(/^var\(\s*(--[\w-]+)\s*(?:,\s*([^)]*))?\)$/)

    // Resolve the declared value to the concrete font-family chain: either the
    // token value from :root, or the literal chain if no token is referenced.
    const chain = varMatch ? rootToken(varMatch[1].slice(2)) : raw

    const families = chain
      .split(',')
      .map((f) => f.trim().replace(/^['"]|['"]$/g, '').toLowerCase())

    expect(families.length).toBeGreaterThan(0)
    expect(families[families.length - 1]).toBe('sans-serif')
  })
})
