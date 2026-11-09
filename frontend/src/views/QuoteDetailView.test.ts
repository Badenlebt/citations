import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { QuoteDetail } from '@/api'
import { routes, RouteName } from '@/router'

// Mock the API module but keep the real ApiError (the view does
// `instanceof ApiError` checks). Only `api` is replaced with spies.
// `vi.hoisted` lets the spy exist before the hoisted `vi.mock` factory runs.
const { getQuote } = vi.hoisted(() => ({ getQuote: vi.fn() }))
vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  return { ...actual, api: { ...actual.api, getQuote } }
})

import QuoteDetailView from './QuoteDetailView.vue'

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

async function mountView(router: Router) {
  const wrapper = mount(QuoteDetailView, {
    props: { id: '42' },
    global: {
      plugins: [router],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

const baseQuote: QuoteDetail = {
  id: 42,
  text: 'Der Mensch ist zur Freiheit verurteilt.',
  languageId: 1,
  languageName: 'Deutsch',
  source: { id: 7, name: 'Jean-Paul Sartre', linkUrl: null },
}

describe('QuoteDetailView rendering', () => {
  beforeEach(() => {
    getQuote.mockReset()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  // Req 4.2: source name rendered as a link to the source single view.
  it('renders the source name as a link targeting the source page (Req 4.2)', async () => {
    getQuote.mockResolvedValue(baseQuote)
    const wrapper = await mountView(makeRouter())

    const sourceLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((link) => (link.text() ?? '').includes('Jean-Paul Sartre'))

    expect(sourceLink).toBeDefined()
    expect(sourceLink!.props('to')).toEqual({
      name: RouteName.SourceDetail,
      params: { id: '7' },
    })
    expect(sourceLink!.text()).toBe('Jean-Paul Sartre')
  })

  // Req 4.3: a source citation WITH a link URL renders an external reference.
  it('renders an external reference when the source has a link URL (Req 4.3)', async () => {
    getQuote.mockResolvedValue({
      ...baseQuote,
      source: { id: 7, name: 'Jean-Paul Sartre', linkUrl: 'https://example.org/sartre' },
    })
    const wrapper = await mountView(makeRouter())

    const external = wrapper.find('.quote-detail-view__external-link')
    expect(external.exists()).toBe(true)
    expect(external.attributes('href')).toBe('https://example.org/sartre')
  })

  // Req 4.4: without a link URL, no external reference is rendered.
  it('renders no external reference when the source has no link URL (Req 4.4)', async () => {
    getQuote.mockResolvedValue({
      ...baseQuote,
      source: { id: 7, name: 'Jean-Paul Sartre', linkUrl: null },
    })
    const wrapper = await mountView(makeRouter())

    expect(wrapper.find('.quote-detail-view__external-link').exists()).toBe(false)
    // The source name link is still present.
    expect(wrapper.find('.quote-detail-view__source-link').exists()).toBe(true)
  })

  it('matches the layout snapshot without a link URL', async () => {
    getQuote.mockResolvedValue(baseQuote)
    const wrapper = await mountView(makeRouter())
    expect(wrapper.html()).toMatchSnapshot()
  })

  it('matches the layout snapshot with a link URL', async () => {
    getQuote.mockResolvedValue({
      ...baseQuote,
      source: { id: 7, name: 'Jean-Paul Sartre', linkUrl: 'https://example.org/sartre' },
    })
    const wrapper = await mountView(makeRouter())
    expect(wrapper.html()).toMatchSnapshot()
  })
})
