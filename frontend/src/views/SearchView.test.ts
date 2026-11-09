import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { SearchResponse } from '@/api'
import { routes, RouteName } from '@/router'

const { search, getLanguages } = vi.hoisted(() => ({
  search: vi.fn(),
  getLanguages: vi.fn(),
}))
vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  return { ...actual, api: { ...actual.api, search, getLanguages } }
})

import SearchView from './SearchView.vue'

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

async function mountView(router: Router) {
  const wrapper = mount(SearchView, {
    global: {
      plugins: [router],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

const sampleResults: SearchResponse = {
  items: [
    {
      id: 1,
      text: 'Der Mensch ist zur Freiheit verurteilt.',
      languageId: 1,
      languageName: 'Deutsch',
      source: { id: 7, name: 'Jean-Paul Sartre' },
    },
  ],
  total: 1,
  page: 1,
  pageSize: 20,
}

describe('SearchView rendering', () => {
  beforeEach(() => {
    search.mockReset()
    getLanguages.mockReset()
    getLanguages.mockResolvedValue([])
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('matches the layout snapshot in the initial empty state', async () => {
    const router = makeRouter()
    const wrapper = await mountView(router)
    expect(wrapper.html()).toMatchSnapshot()
  })

  it('matches the layout snapshot with results and links each item to its quote', async () => {
    search.mockResolvedValue(sampleResults)
    const router = makeRouter()
    await router.push({ name: RouteName.Search, query: { q: 'freiheit' } })
    const wrapper = await mountView(router)

    const quoteLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((link) => (link.props('to') as { name?: string })?.name === RouteName.QuoteDetail)
    expect(quoteLink).toBeDefined()
    expect(quoteLink!.props('to')).toEqual({
      name: RouteName.QuoteDetail,
      params: { id: '1' },
    })

    expect(wrapper.html()).toMatchSnapshot()
  })
})
