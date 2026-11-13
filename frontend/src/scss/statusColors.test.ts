import { describe, expect, it } from 'vitest'
import { resolve } from 'node:path'
import * as sass from 'sass'
import fc from 'fast-check'

// Resolve the three status-color tokens from the single source of truth
// (_variables.scss). We compile the SCSS so that the values we assert on are
// exactly the tokens the application ships — not duplicated literals.
function resolveStatusColors(): Record<'success' | 'error' | 'info', string> {
  // Vitest runs with the frontend package root as cwd.
  const variablesPath = resolve(process.cwd(), 'src/scss/_variables.scss')
  const css = sass.compile(variablesPath).css

  const read = (prop: string): string => {
    const match = css.match(new RegExp(`${prop}\\s*:\\s*([^;]+);`))
    if (!match) {
      throw new Error(`Status-Token ${prop} wurde in _variables.scss nicht gefunden`)
    }
    return match[1].trim().toLowerCase()
  }

  return {
    success: read('--color-success'),
    error: read('--color-error'),
    info: read('--color-info'),
  }
}

describe('Statusfarben (Property 4)', () => {
  const colors = resolveStatusColors()
  const statuses = ['success', 'error', 'info'] as const

  // Feature: ui-redesign, Property 4: Statusfarben sind unterscheidbar
  // Fuer jedes Paar der drei Statusfarben (Erfolg, Fehler, Hinweis) gilt:
  // die beiden Farbwerte sind ungleich.
  it('sind paarweise unterscheidbar', () => {
    fc.assert(
      fc.property(
        fc.constantFrom(...statuses),
        fc.constantFrom(...statuses),
        (a, b) => {
          if (a === b) {
            // Gleicher Status -> identischer Wert, trivial erfuellt.
            expect(colors[a]).toBe(colors[b])
          } else {
            // Verschiedene Status -> die Farbwerte muessen ungleich sein.
            expect(colors[a]).not.toBe(colors[b])
          }
        },
      ),
      { numRuns: 100 },
    )
  })
})
