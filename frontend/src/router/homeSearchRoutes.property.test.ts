import { describe, expect, it } from 'vitest'
import fc from 'fast-check'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes, RouteName } from '@/router'

// Feature: random-quote-homepage, Property 15: Suchverknüpfungen erhalten die Query-Parameter, die Startseite ignoriert sie
//
// Validates: Requirements 5.8, 5.10

const router = createRouter({ history: createMemoryHistory(), routes })

const queryArb = fc.record({
  q: fc.string(),
  languages: fc.string(),
  sourceId: fc.string(),
  page: fc.string(),
})

describe('Property 15: Suchverknüpfungen erhalten die Query-Parameter, die Startseite ignoriert sie', () => {
  it('Search löst mit unveränderter Query auf /search auf', () => {
    fc.assert(
      fc.property(queryArb, (query) => {
        const resolved = router.resolve({ name: RouteName.Search, query })
        expect(resolved.path).toBe('/search')
        expect(resolved.name).toBe(RouteName.Search)
        expect(resolved.query).toEqual(query)
      }),
      { numRuns: 100 },
    )
  })

  it('derselbe Query-Satz an "/" löst weiterhin auf die Startseite auf', () => {
    fc.assert(
      fc.property(queryArb, (query) => {
        const resolved = router.resolve({ path: '/', query })
        expect(resolved.name).toBe(RouteName.Home)
        expect(resolved.path).toBe('/')
      }),
      { numRuns: 100 },
    )
  })
})
