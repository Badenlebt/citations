import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import fc from 'fast-check'

// Design tokens are the single source of truth. We read the hex values
// straight out of _variables.scss so this test stays bound to the central
// definition (Req 1.1/2.1) rather than duplicating literals. Vitest runs with
// the frontend package as cwd, so the token file resolves from there.
const variablesScss = readFileSync(
  resolve(process.cwd(), 'src/scss/_variables.scss'),
  'utf8',
)

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

/** Read a `$name: #rrggbb;` SCSS color token, normalised to 6-digit lowercase. */
function token(name: string): string {
  const match = variablesScss.match(
    new RegExp('\\$' + name + '\\s*:\\s*(#[0-9a-fA-F]{3,6})\\s*;'),
  )
  if (!match) {
    throw new Error('token $' + name + ' not found in _variables.scss')
  }
  return normaliseHex(match[1])
}

const palette = {
  background: token('color-background'),
  text: token('color-text'),
  primary: token('color-primary'),
  success: token('color-success'),
  error: token('color-error'),
  info: token('color-info'),
}

// WCAG 2.1 relative-luminance + contrast-ratio formula.
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

/** WCAG contrast ratio (lighter + 0.05) / (darker + 0.05), range 1..21. */
function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)
  const lighter = Math.max(la, lb)
  const darker = Math.min(la, lb)
  return (lighter + 0.05) / (darker + 0.05)
}

// The combinations the design mandates (design.md, Property 3): white body
// text and the status colours are normal text on the Gunmetal background
// (>= 4.5:1); the accent colour #C15BEB is used for links / graphical
// controls (>= 3:1). Each combination carries the threshold it must meet.
const NORMAL_TEXT = 4.5
const LARGE_OR_GRAPHICAL = 3

interface Combination {
  readonly name: string
  readonly foreground: string
  readonly background: string
  readonly threshold: number
}

const combinations: readonly Combination[] = [
  {
    name: 'white text on background',
    foreground: palette.text,
    background: palette.background,
    threshold: NORMAL_TEXT,
  },
  {
    name: 'primary accent link on background',
    foreground: palette.primary,
    background: palette.background,
    threshold: LARGE_OR_GRAPHICAL,
  },
  {
    name: 'success status on background',
    foreground: palette.success,
    background: palette.background,
    threshold: NORMAL_TEXT,
  },
  {
    name: 'error status on background',
    foreground: palette.error,
    background: palette.background,
    threshold: NORMAL_TEXT,
  },
  {
    name: 'info status on background',
    foreground: palette.info,
    background: palette.background,
    threshold: NORMAL_TEXT,
  },
]

describe('WCAG AA contrast of palette and status colours', () => {
  it('computes white-on-black contrast as the WCAG maximum of 21:1', () => {
    expect(contrastRatio('#ffffff', '#000000')).toBeCloseTo(21, 2)
  })

  it('computes identical colours as a 1:1 ratio', () => {
    expect(contrastRatio(palette.background, palette.background)).toBeCloseTo(1, 5)
  })

  it('includes #fff on #282D37 and the #C15BEB accent in the checked set', () => {
    expect(palette.text).toBe('#ffffff')
    expect(palette.background).toBe('#282d37')
    expect(palette.primary).toBe('#c15beb')
  })

  // Feature: ui-redesign, Property 3: Kontrastverhältnisse erfüllen WCAG AA
  it('every defined text/background combination meets its WCAG AA threshold', () => {
    fc.assert(
      fc.property(fc.constantFrom(...combinations), (combination) => {
        const ratio = contrastRatio(combination.foreground, combination.background)
        expect(
          ratio,
          combination.name +
            ' (' +
            combination.foreground +
            ' on ' +
            combination.background +
            ') must meet ' +
            combination.threshold +
            ':1 but was ' +
            ratio.toFixed(3) +
            ':1',
        ).toBeGreaterThanOrEqual(combination.threshold)
      }),
      { numRuns: 100 },
    )
  })
})
