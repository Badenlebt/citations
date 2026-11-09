import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { App } from 'vue'
import type { Language, QuoteListItem, QuoteListResponse } from '@/api'
import QuoteManager from './QuoteManager.vue'
import { createI18n, installI18n } from '@/i18n'

// Pin the UI locale to German so admin labels ("Keine Quelle …") are
// deterministic regardless of the test runner's browser language.
const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

// `@/api` is mocked so we control the admin list endpoint (GET /api/quotes,
// Req 18) and can observe the page/pageSize progression of the collection loop.
vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: {
      listQuotes: vi.fn(),
    },
  }
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = (await import('@/api')).api as any

const languages: Language[] = [
  { id: 1, name: 'Deutsch' },
  { id: 2, name: 'English' },
]

function makeQuote(id: number): QuoteListItem {
  return {
    id,
    text: `Zitat Nummer ${id}`,
    languageId: 1,
    languageName: 'Deutsch',
    source: { id: 10 + id, name: `Quelle ${id}` },
  }
}

function page(items: QuoteListItem[], total: number, pageNo = 1): QuoteListResponse {
  return { items, total, page: pageNo, pageSize: 100 }
}

function mountManager(sources: { id: number; name: string }[] = []) {
  return mount(QuoteManager, {
    props: { sources, languages },
    global: { plugins: [i18nPlugin] },
  })
}

describe('QuoteManager quote list rendering (Req 18.1)', () => {
  beforeEach(() => {
    mockedApi.listQuotes.mockReset()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders the paginated quote list returned by api.listQuotes', async () => {
    const items = [makeQuote(1), makeQuote(2), makeQuote(3)]
    mockedApi.listQuotes.mockResolvedValue(page(items, items.length))

    const wrapper = mountManager()
    await flushPromises()

    const rows = wrapper.findAll('.quote-manager__item')
    expect(rows).toHaveLength(3)

    // Row text and the language · source meta both render.
    expect(wrapper.text()).toContain('Zitat Nummer 1')
    expect(wrapper.text()).toContain('Zitat Nummer 3')
    const firstMeta = wrapper.find('.quote-manager__item-meta')
    expect(firstMeta.text()).toContain('Deutsch')
    expect(firstMeta.text()).toContain('Quelle 1')
  })

  it('collects and renders all quotes across multiple pages (page-advance loop)', async () => {
    // A full first page (items.length === pageSize) signals more pages follow;
    // total > pageSize keeps the loop going. The final page is partial, which
    // stops the loop once the collected count reaches total.
    const firstPage = Array.from({ length: 100 }, (_, i) => makeQuote(i + 1))
    const secondPage = [makeQuote(101), makeQuote(102)]

    mockedApi.listQuotes
      .mockResolvedValueOnce(page(firstPage, 102, 1))
      .mockResolvedValueOnce(page(secondPage, 102, 2))

    const wrapper = mountManager()
    await flushPromises()

    const rows = wrapper.findAll('.quote-manager__item')
    expect(rows).toHaveLength(102)
    expect(wrapper.text()).toContain('Zitat Nummer 1')
    expect(wrapper.text()).toContain('Zitat Nummer 101')
    expect(wrapper.text()).toContain('Zitat Nummer 102')

    // Two calls with the expected page/pageSize progression (Seitenwechsel).
    expect(mockedApi.listQuotes).toHaveBeenCalledTimes(2)
    expect(mockedApi.listQuotes).toHaveBeenNthCalledWith(1, { page: 1, pageSize: 100 })
    expect(mockedApi.listQuotes).toHaveBeenNthCalledWith(2, { page: 2, pageSize: 100 })
  })

  it('stops paging on an empty final page even if total is not yet reached', async () => {
    // Defensive: a full page followed by an empty page terminates the loop
    // without looping forever.
    const firstPage = Array.from({ length: 100 }, (_, i) => makeQuote(i + 1))

    mockedApi.listQuotes
      .mockResolvedValueOnce(page(firstPage, 150, 1))
      .mockResolvedValueOnce(page([], 150, 2))

    const wrapper = mountManager()
    await flushPromises()

    expect(wrapper.findAll('.quote-manager__item')).toHaveLength(100)
    expect(mockedApi.listQuotes).toHaveBeenCalledTimes(2)
    expect(mockedApi.listQuotes).toHaveBeenNthCalledWith(2, { page: 2, pageSize: 100 })
  })

  it('shows the empty-state message when no quotes exist', async () => {
    mockedApi.listQuotes.mockResolvedValue(page([], 0))

    const wrapper = mountManager()
    await flushPromises()

    expect(wrapper.findAll('.quote-manager__item')).toHaveLength(0)
    expect(wrapper.find('.quote-manager__empty').text()).toBe('Noch keine Zitate vorhanden.')
  })
})

describe('QuoteManager source dropdown (Req 19.2)', () => {
  beforeEach(() => {
    mockedApi.listQuotes.mockReset()
    mockedApi.listQuotes.mockResolvedValue(page([], 0))
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('populates the quote source select from the sources list', async () => {
    const sources = [
      { id: 7, name: 'Jean-Paul Sartre' },
      { id: 8, name: 'Immanuel Kant' },
    ]
    const wrapper = mountManager(sources)
    await flushPromises()

    // The first <select> is the source dropdown (the second is the language one).
    const selects = wrapper.findAll('select')
    const sourceSelect = selects[0]
    const options = sourceSelect.findAll('option')

    // One "no source" option plus one option per source.
    expect(options).toHaveLength(sources.length + 1)
    expect(options[0].text()).toBe('Keine Quelle (Unbekannte Quelle)')
    expect(options[1].text()).toBe('Jean-Paul Sartre')
    expect(options[2].text()).toBe('Immanuel Kant')
  })

  it('renders only the no-source option when the sources list is empty', async () => {
    const wrapper = mountManager([])
    await flushPromises()

    const sourceSelect = wrapper.findAll('select')[0]
    const options = sourceSelect.findAll('option')
    expect(options).toHaveLength(1)
    expect(options[0].text()).toBe('Keine Quelle (Unbekannte Quelle)')
  })
})
