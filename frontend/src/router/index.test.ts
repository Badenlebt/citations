import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes, RouteName } from './index'

// Build a router with memory history so we can resolve routes without a DOM.
function testRouter() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('router config', () => {
  it('resolves the search page at the root path', () => {
    const resolved = testRouter().resolve('/')
    expect(resolved.name).toBe(RouteName.Search)
  })

  it('resolves the quote detail route and exposes the id as a prop', () => {
    const resolved = testRouter().resolve('/quotes/12')
    expect(resolved.name).toBe(RouteName.QuoteDetail)
    expect(resolved.params.id).toBe('12')
    expect(resolved.matched[0].props.default).toBe(true)
  })

  it('resolves the source detail route', () => {
    const resolved = testRouter().resolve('/sources/7')
    expect(resolved.name).toBe(RouteName.SourceDetail)
    expect(resolved.params.id).toBe('7')
  })

  it('resolves the admin area and marks it as auth-protected', () => {
    const resolved = testRouter().resolve('/admin')
    expect(resolved.name).toBe(RouteName.Admin)
    expect(resolved.meta.requiresAuth).toBe(true)
  })

  it('falls back to the not-found view for unknown paths', () => {
    const resolved = testRouter().resolve('/does/not/exist')
    expect(resolved.name).toBe(RouteName.NotFound)
  })
})
