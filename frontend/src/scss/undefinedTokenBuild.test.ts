import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'

// Task 9.3 — Integrationstest: Build bricht bei undefiniertem Token ab
//
// Requirement 1.5: Verweist ein Oberflaechenelement auf einen Design-Token,
// der an der zentralen Definitionsstelle (_variables.scss) nicht definiert ist,
// SHALL der Build dies als Fehler melden und abbrechen.
//
// Vite kompiliert `.scss` ueber dieselbe `sass`-API (dart-sass). Ein
// `vite build` bricht daher genau dann ab, wenn `sass` beim Kompilieren einen
// Fehler wirft. Dieser Test weist das fail-fast-Verhalten direkt an der
// `sass`-Kompilierung nach — ohne echte Projekt-SCSS zu veraendern oder echte
// Build-Artefakte zu erzeugen: der fehlerhafte Verweis lebt ausschliesslich in
// einem In-Memory-SCSS-String (`compileString`).
//
// Die Pfadaufloesung erfolgt — wie in den Schwester-Tests — ueber
// `process.cwd()` (Vitest laeuft mit dem frontend-Paket als cwd). `loadPaths`
// zeigt auf `src/scss`, damit `@use 'variables'` die echte, zentrale
// Token-Definition aufloest.
const scssDir = resolve(process.cwd(), 'src/scss')

function compile(scss: string): string {
  return sass.compileString(scss, { loadPaths: [scssDir] }).css
}

describe('Build-Abbruch bei undefiniertem Design-Token (Req 1.5)', () => {
  it('bricht die Kompilierung mit "Undefined variable" ab, wenn ein nicht definierter Token referenziert wird', () => {
    // Referenziert einen Token, der in _variables.scss NICHT existiert.
    const scssWithUndefinedToken = `
      @use 'variables' as tokens;
      .x { color: tokens.$color-does-not-exist; }
    `

    // Der Build (sass in Vite) muss abbrechen -> compileString wirft.
    let thrown: unknown
    try {
      compile(scssWithUndefinedToken)
    } catch (error) {
      thrown = error
    }

    expect(thrown, 'compileString haette bei undefiniertem Token werfen muessen').toBeDefined()
    expect(String((thrown as Error).message)).toContain('Undefined variable')
  })

  it('kompiliert fehlerfrei, wenn ausschliesslich definierte Token referenziert werden (Gegenprobe)', () => {
    const scssWithDefinedToken = `
      @use 'variables' as tokens;
      .x { color: tokens.$color-primary; }
    `

    expect(() => compile(scssWithDefinedToken)).not.toThrow()

    const css = compile(scssWithDefinedToken)
    // Der gueltige Token-Wert landet im kompilierten CSS -> Build liefe durch.
    expect(css.toLowerCase()).toContain('#c15beb')
  })
})
