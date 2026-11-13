import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { App } from 'vue'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import type { Language, SourceListItem, SourceListResponse } from '@/api'
import { createI18n, installI18n } from '@/i18n'
import { routes } from '@/router'

// Pin the UI locale to German for deterministic labels (admin tabs etc.).
const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

// Mock `@/api`. AdminView orchestrates the managers: on mount it probes the
// session by loading the full source list (GET /api/sources, Req 19) and the
// languages, then renders QuoteManager (which calls listQuotes) and
// SourceManager. We provide every method the mounted tree touches.
const { listSources, getLanguages, listQuotes, logout } = vi.hoisted(() => ({
  listSources: vi.fn(),
  getLanguages: vi.fn(),
  listQuotes: vi.fn(),
  logout: vi.fn(),
}))
vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: { listSources, getLanguages, listQuotes, logout },
  }
})

import AdminView from './AdminView.vue'
import SourceManager from '@/components/admin/SourceManager.vue'

const languages: Language[] = [{ id: 1, name: 'Deutsch' }]

function sourcePage(
  items: SourceListItem[],
  total: number,
  pageNo = 1,
): SourceListResponse {
  return { items, total, page: pageNo, pageSize: 100 }
}

function makeSource(id: number, isUnknown = false): SourceListItem {
  return { id, name: isUnknown ? 'unbekannt' : `Quelle ${id}`, isUnknown }
}

// AdminView now derives its active tab from the route query, so the mounted
// tree needs a router in context.
function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

function mountView() {
  const router = makeRouter()
  return mount(AdminView, { global: { plugins: [router, i18nPlugin] } })
}

describe('AdminView source list paging (Req 19.1)', () => {
  beforeEach(() => {
    listSources.mockReset()
    getLanguages.mockReset()
    listQuotes.mockReset()
    logout.mockReset()
    getLanguages.mockResolvedValue(languages)
    // QuoteManager mounts and loads its own list; keep it empty here.
    listQuotes.mockResolvedValue({ items: [], total: 0, page: 1, pageSize: 100 })
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('collects sources across multiple pages and passes them to SourceManager', async () => {
    // One Unknown source (Req 19.5) plus a full first page forces a second
    // request; the partial second page stops the paging loop.
    const firstPage = [
      makeSource(1, true),
      ...Array.from({ length: 99 }, (_, i) => makeSource(i + 2)),
    ]
    const secondPage = [makeSource(101), makeSource(102)]

    listSources
      .mockResolvedValueOnce(sourcePage(firstPage, 102, 1))
      .mockResolvedValueOnce(sourcePage(secondPage, 102, 2))

    const wrapper = mountView()
    await flushPromises()

    // Authenticated: the management UI (not the login form) is shown.
    expect(wrapper.find('.admin-login').exists()).toBe(false)

    // Paging advanced through both pages with the expected progression.
    expect(listSources).toHaveBeenCalledTimes(2)
    expect(listSources).toHaveBeenNthCalledWith(1, { page: 1, pageSize: 100 })
    expect(listSources).toHaveBeenNthCalledWith(2, { page: 2, pageSize: 100 })

    // All 102 collected sources reach the SourceManager list.
    const sourceManager = wrapper.findComponent(SourceManager)
    expect(sourceManager.exists()).toBe(true)
    expect(sourceManager.props('sources')).toHaveLength(102)

    // The SourceManager renders a row per collected source, incl. the Unknown.
    const rows = sourceManager.findAll('.source-manager__item')
    expect(rows).toHaveLength(102)
    expect(sourceManager.text()).toContain('unbekannt')
    expect(sourceManager.text()).toContain('Quelle 102')
  })

  it('falls back to the login gate when listSources returns 401', async () => {
    const { ApiError } = await vi.importActual<typeof import('@/api')>('@/api')
    listSources.mockRejectedValue(new ApiError(401, 'UNAUTHORIZED', 'unauthorized'))

    const wrapper = mountView()
    await flushPromises()

    // Unauthorized probe → login form, no source rows.
    expect(wrapper.find('.admin-login').exists()).toBe(true)
  })
})
