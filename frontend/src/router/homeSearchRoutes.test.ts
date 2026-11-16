import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes, RouteName } from '@/router'

// Validates: Requirements 5.2, 5.3
function makeRouter() {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('Home/Search routes', () => {
  it("'/' resolves to RouteName.Home", () => {
    const resolved = makeRouter().resolve('/')
    expect(resolved.name).toBe(RouteName.Home)
    expect(resolved.path).toBe('/')
  })

  it("'/search' resolves to RouteName.Search", () => {
    const resolved = makeRouter().resolve('/search')
    expect(resolved.name).toBe(RouteName.Search)
    expect(resolved.path).toBe('/search')
  })

  it('named routes resolve back to their paths', () => {
    const router = makeRouter()
    expect(router.resolve({ name: RouteName.Home }).path).toBe('/')
    expect(router.resolve({ name: RouteName.Search }).path).toBe('/search')
  })
})
