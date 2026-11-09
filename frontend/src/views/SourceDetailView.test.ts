import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { SourceDetail } from '@/api'
import { routes, RouteName } from '@/router'

const { getSource } = vi.hoisted(() => ({ getSource: vi.fn() }))
vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  return { ...actual, api: { ...actual.api, getSource } }
})

import SourceDetailView from './SourceDetailView.vue'

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

async function mountView(router: Router) {
  const wrapper = mount(SourceDetailView, {
    props: { id: '7' },
    global: {
      plugins: [router],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

const baseSource: SourceDetail = {
  id: 7,
  name: 'Jean-Paul Sartre',
  birthDate: '1905-06-21',
  deathDate: '1980-04-15',
  description: 'Philosoph und Schriftsteller.',
  linkUrl: 'https://example.org/sartre',
}

describe('SourceDetailView rendering', () => {
  beforeEach(() => {
    getSource.mockReset()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  // Req 5.3: the source page links into a source-filtered, paginated search
  // carrying the source id as a `sourceId` query param.
  it('links to the source-filtered search carrying the sourceId query (Req 5.3)', async () => {
    getSource.mockResolvedValue(baseSource)
    const wrapper = await mountView(makeRouter())

    const filteredLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((link) => {
        const to = link.props('to') as { name?: string; query?: Record<string, unknown> }
        return to?.name === RouteName.Search && to?.query?.sourceId !== undefined
      })

    expect(filteredLink).toBeDefined()
    expect(filteredLink!.props('to')).toEqual({
      name: RouteName.Search,
      query: { sourceId: '7' },
    })
  })

  it('matches the layout snapshot with all optional fields set', async () => {
    getSource.mockResolvedValue(baseSource)
    const wrapper = await mountView(makeRouter())
    expect(wrapper.html()).toMatchSnapshot()
  })

  it('matches the layout snapshot with optional fields unset', async () => {
    getSource.mockResolvedValue({
      id: 7,
      name: 'Unbekannt',
      birthDate: null,
      deathDate: null,
      description: null,
      linkUrl: null,
    })
    const wrapper = await mountView(makeRouter())
    expect(wrapper.html()).toMatchSnapshot()
  })
})
