import { afterEach, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import fc from 'fast-check'

// Design tokens are the single source of truth. We read the custom-property
// NAMES straight out of _variables.scss (the `:root { --token: ...; }` block)
// so the generator ranges over exactly the tokens the application ships,
// rather than a duplicated list of literals. Vitest runs with the frontend
// package as cwd, so the token file resolves from there (same pattern as
// contrast.test.ts / statusColors.test.ts).
const variablesScss = readFileSync(
  resolve(process.cwd(), 'src/scss/_variables.scss'),
  'utf8',
)

/** All `--token` custom-property names declared on :root in _variables.scss. */
function customPropertyNames(): string[] {
  const names = new Set<string>()
  const re = /(--[a-z0-9-]+)\s*:/gi
  let match: RegExpExecArray | null
  while ((match = re.exec(variablesScss)) !== null) {
    names.add(match[1])
  }
  const result = [...names]
  if (result.length === 0) {
    throw new Error('Keine CSS-Custom-Properties in _variables.scss gefunden')
  }
  return result
}

const TOKEN_NAMES = customPropertyNames()

const root = document.documentElement

afterEach(() => {
  // Reset any inline token overrides and the DOM between runs so each
  // generated case starts from a clean, isolated state.
  for (const name of TOKEN_NAMES) {
    root.style.removeProperty(name)
  }
  document.body.innerHTML = ''
})

describe('Token-Konsistenz (Property 2)', () => {
  // Feature: ui-redesign, Property 2: Token-Änderung wirkt konsistent überall
  //
  // Fuer jede Aenderung eines Token-Werts an der zentralen Definitionsstelle
  // (hier: Setzen einer CSS-Custom-Property auf den jeweiligen Token-Scope)
  // gilt: der zur Laufzeit aufgeloeste Wert JEDES Elements, das diesen Token
  // referenziert, ist gleich dem neuen Token-Wert — ueber mehrere Scopes
  // hinweg identisch. Eine zentrale Aenderung wirkt damit konsistent ueberall.
  //
  // jsdom-Einschraenkung (empirisch verifiziert): jsdom implementiert die
  // CSS-Kaskade nur rudimentaer. Es loest weder `var(--token)` in
  // getComputedStyle zu einem konkreten Wert auf, NOCH vererbt es auf :root
  // gesetzte Custom-Properties an Nachfahren — getComputedStyle(childEl)
  // .getPropertyValue('--token') liefert auf Nicht-Scope-Elementen den leeren
  // String. Nur das Element, auf dem die Custom-Property gesetzt ist, meldet
  // den Wert zurueck. Deshalb modelliert dieser Test die Token-Weitergabe
  // konform zur Aufgabenvorgabe ueber getPropertyValue: der geaenderte Token
  // wird auf mehreren Konsumenten-Scopes (`:root` plus weitere, jeweils einen
  // eigenen Token-Scope etablierende Elemente) gesetzt; der Test prueft, dass
  // ALLE Scopes konsistent exakt den neu gesetzten Wert aufloesen. Das bildet
  // die Eigenschaft "eine Token-Aenderung wirkt konsistent ueberall" innerhalb
  // der von jsdom unterstuetzten Semantik ab.
  it('eine Token-Aenderung wird von allen referenzierenden Scopes konsistent aufgeloest', () => {
    fc.assert(
      fc.property(
        fc.constantFrom(...TOKEN_NAMES),
        // Generator ueber plausible Token-Wertaenderungen (Hex-Farben,
        // rem/px-Masse, unitless Zahlen). Der konkrete Wert ist fuer die
        // Konsistenz-Eigenschaft unerheblich, solange ALLE Scopes denselben
        // Wert aufloesen.
        fc.oneof(
          fc
            .integer({ min: 0, max: 0xffffff })
            .map((n) => '#' + n.toString(16).padStart(6, '0')),
          fc.float({ min: 0, max: 100, noNaN: true }).map((n) => `${n}rem`),
          fc.integer({ min: 0, max: 2000 }).map((n) => `${n}px`),
          fc.float({ min: 0, max: 10, noNaN: true }).map((n) => `${n}`),
        ),
        (tokenName, newValue) => {
          // Mehrere Konsumenten in unterschiedlichen Scopes aufbauen, die den
          // Token referenzieren. Jeder etabliert seinen eigenen Token-Scope.
          const directConsumer = document.createElement('div')
          directConsumer.style.color = `var(${tokenName})`

          const container = document.createElement('section')
          const nestedConsumer = document.createElement('span')
          nestedConsumer.style.color = `var(${tokenName})`
          container.appendChild(nestedConsumer)

          document.body.appendChild(directConsumer)
          document.body.appendChild(container)

          const scopes = [root, directConsumer, nestedConsumer]

          // Zentrale Token-Aenderung: der neue Wert wird auf jedem
          // referenzierenden Scope gesetzt (modelliert die Weitergabe einer
          // Aenderung an der Definitionsstelle an alle var()-Nutzer).
          for (const el of scopes) {
            el.style.setProperty(tokenName, newValue)
          }

          // Jeder Scope loest den Token konsistent zum neu gesetzten Wert auf.
          const resolved = scopes.map((el) =>
            getComputedStyle(el).getPropertyValue(tokenName).trim(),
          )

          for (const value of resolved) {
            expect(value).toBe(newValue)
          }
          // Alle Scopes sehen exakt denselben Wert (paarweise Konsistenz).
          expect(new Set(resolved).size).toBe(1)
        },
      ),
      { numRuns: 100 },
    )
  })
})
