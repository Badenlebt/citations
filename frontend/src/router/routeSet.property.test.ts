import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import { routes, RouteName } from '@/router'
import SearchView from '@/views/SearchView.vue'
import QuoteDetailView from '@/views/QuoteDetailView.vue'
import SourceDetailView from '@/views/SourceDetailView.vue'
import AdminView from '@/views/AdminView.vue'
import NotFoundView from '@/views/NotFoundView.vue'

// =============================================================================
// Task 9.1 — Property-Test: Routen- und View-Menge bleibt unverändert
//
// Feature: ui-redesign, Property 9: Routen- und View-Menge bleibt unverändert
//
// Design (Property 9, Req 8.1): Das UI-Redesign ändert ausschließlich Styling
// und darf Routen, Pfade und die je Route gerenderte View-Komponente NICHT
// verändern. Wir definieren die im Redesign unveränderte Referenzmenge aus
// {Routenname, Pfad, View-Komponente} explizit (die 5 bekannten Routen) und
// prüfen Mengengleichheit gegen die tatsächliche Router-Definition `routes`.
//
// Vergleich der View-Komponenten per Referenzidentität: die aus `@/views`
// importierten SFC-Objekte sind exakt dieselben Objekte, die `routes`
// referenziert — ein Austausch der View-Komponente wäre damit erkennbar.
//
// Validates: Requirements 8.1
// =============================================================================

/** Ein Routeneintrag, auf die redesign-relevanten Felder reduziert. */
interface RouteTriple {
  name: string
  path: string
  component: unknown
}

/** Stabiler Schlüssel zur Mengenbildung über {Name, Pfad, Komponente}. */
function keyOf(route: RouteTriple, componentId: (c: unknown) => number): string {
  return `${route.name}\u0000${route.path}\u0000${componentId(route.component)}`
}

// Die explizit erwartete, im Redesign unveränderte Referenzmenge.
const expectedRoutes: RouteTriple[] = [
  { name: RouteName.Search, path: '/', component: SearchView },
  { name: RouteName.QuoteDetail, path: '/quotes/:id', component: QuoteDetailView },
  { name: RouteName.SourceDetail, path: '/sources/:id', component: SourceDetailView },
  { name: RouteName.Admin, path: '/admin', component: AdminView },
  { name: RouteName.NotFound, path: '/:pathMatch(.*)*', component: NotFoundView },
]

// Die tatsächliche Menge, aus der Router-Definition abgeleitet.
const actualRoutes: RouteTriple[] = routes.map((route) => ({
  name: String(route.name),
  path: route.path,
  component: route.component,
}))

// Referenzidentität der Komponenten über eine Objekt-Identitätstabelle abbilden,
// damit Komponenten als Teil des Mengenschlüssels vergleichbar werden.
function makeComponentId(): (c: unknown) => number {
  const ids = new Map<unknown, number>()
  let next = 0
  return (c) => {
    if (!ids.has(c)) {
      ids.set(c, next++)
    }
    return ids.get(c) as number
  }
}

describe('router route/view set is unchanged by the redesign (Property 9)', () => {
  // Feature: ui-redesign, Property 9: Routen- und View-Menge bleibt unverändert
  // Generator wählt über die Indizes der erwarteten Routen; für jede generierte
  // Route muss der {Name, Pfad, Komponente}-Schlüssel in beiden Mengen
  // vorkommen (Name↔Pfad↔Komponente konsistent). Zusätzlich wird die
  // Kardinalität beider Mengen auf Gleichheit geprüft.
  it('matches the explicit reference set as a set, including cardinality', () => {
    const componentId = makeComponentId()
    const expectedKeys = new Set(expectedRoutes.map((r) => keyOf(r, componentId)))
    const actualKeys = new Set(actualRoutes.map((r) => keyOf(r, componentId)))

    fc.assert(
      fc.property(fc.nat({ max: expectedRoutes.length - 1 }), (index) => {
        const expected = expectedRoutes[index]
        const key = keyOf(expected, componentId)
        // Jede erwartete Route existiert mit konsistentem Name↔Pfad↔Komponente
        // in der tatsächlichen Router-Definition...
        expect(actualKeys.has(key)).toBe(true)
        // ...und bleibt Teil der erwarteten Referenzmenge.
        expect(expectedKeys.has(key)).toBe(true)
      }),
      { numRuns: 100 },
    )

    // Mengengleichheit in beide Richtungen: keine fehlende und keine
    // zusätzliche Route (Kardinalität + Elementgleichheit).
    expect(actualKeys.size).toBe(expectedKeys.size)
    expect(actualKeys.size).toBe(routes.length)
    for (const key of actualKeys) {
      expect(expectedKeys.has(key)).toBe(true)
    }
  })
})
