import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/api', async () => {
  const actual = await vi.importActual<typeof import('@/api')>('@/api')
  return { ...actual, api: { random: vi.fn() } }
})

import { api, ApiError } from '@/api'
import type { QuoteDetail } from '@/api/types'
import { routes } from '@/router'
import HomeView from './HomeView.vue'

const randomMock = vi.mocked(api.random)

function makeQuote(id: number, text: string): QuoteDetail {
  return {
    id,
    text,
    languageId: 1,
    languageName: 'Deutsch',
    source: { id: 7, name: 'Goethe' },
  } as QuoteDetail
}

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/')
  await router.isReady()
  return mount(HomeView, { global: { plugins: [router] } })
}

describe('HomeView', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    randomMock.mockReset()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('zeigt bei Erfolg Zitat, Quellen- und Zitat-Link (Req 1.2, 1.3)', async () => {
    randomMock.mockResolvedValue(makeQuote(42, 'Hallo Welt'))
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('blockquote').text()).toBe('Hallo Welt')
    const hrefs = wrapper.findAll('figcaption a').map((a) => a.attributes('href'))
    expect(hrefs).toContain('/sources/7')
    expect(hrefs).toContain('/quotes/42')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('zeigt beim Laden genau ein role=status (Req 2.2)', async () => {
    randomMock.mockImplementation(() => new Promise(() => undefined))
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.find('blockquote').exists()).toBe(false)
    wrapper.unmount()
  })

  it('zeigt bei NO_QUOTES den Leerzustand ohne Schaltfläche und ohne Alert (Req 1.9)', async () => {
    randomMock.mockRejectedValue(new ApiError(404, 'NO_QUOTES', 'none'))
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('.home-view__status').exists()).toBe(true)
    expect(wrapper.find('blockquote').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('zeigt bei Fehler role=alert und Nachladen-Schaltfläche (Req 1.8)', async () => {
    randomMock.mockRejectedValue(new ApiError(500, 'INTERNAL_ERROR', 'boom'))
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.find('button.home-view__reload').exists()).toBe(true)
    expect(wrapper.find('blockquote').exists()).toBe(false)
    wrapper.unmount()
  })

  it('ersetzt beim Nachladen das alte Zitat durch das neue (Req 3.5)', async () => {
    randomMock
      .mockResolvedValueOnce(makeQuote(1, 'Erstes'))
      .mockResolvedValueOnce(makeQuote(2, 'Zweites'))
    const wrapper = await mountView()
    await flushPromises()
    expect(wrapper.find('blockquote').text()).toBe('Erstes')

    await wrapper.find('button.home-view__reload').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('blockquote')).toHaveLength(1)
    expect(wrapper.find('blockquote').text()).toBe('Zweites')
    wrapper.unmount()
  })

  it('behält bei Fehler beim Nachladen das alte Zitat und zeigt eine Meldung (Req 3.6)', async () => {
    randomMock
      .mockResolvedValueOnce(makeQuote(1, 'Erstes'))
      .mockRejectedValueOnce(new ApiError(500, 'INTERNAL_ERROR', 'boom'))
    const wrapper = await mountView()
    await flushPromises()

    await wrapper.find('button.home-view__reload').trigger('click')
    await flushPromises()

    expect(wrapper.find('blockquote').text()).toBe('Erstes')
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('wertet nach 10 s ohne Antwort als Fehler (Req 1.8)', async () => {
    randomMock.mockImplementation(() => new Promise(() => undefined))
    const wrapper = await mountView()
    await vi.advanceTimersByTimeAsync(9_999)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.find('button.home-view__reload').exists()).toBe(true)
    wrapper.unmount()
  })

  it('bricht bei Unmount ab: kein Zustand, keine Meldung danach', async () => {
    randomMock.mockImplementation(
      (signal?: AbortSignal) =>
        new Promise((_, reject) => {
          signal?.addEventListener('abort', () => reject(new DOMException('x', 'AbortError')))
        }),
    )
    const wrapper = await mountView()
    const signal = randomMock.mock.calls[0]![0] as AbortSignal
    wrapper.unmount()

    expect(signal.aborted).toBe(true)
    await vi.advanceTimersByTimeAsync(20_000)
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('blockquote').exists()).toBe(false)
  })

  it.each([
    ['Laden', () => randomMock.mockImplementation(() => new Promise(() => undefined))],
    ['Erfolg', () => randomMock.mockResolvedValue(makeQuote(1, 'Text'))],
    ['Fehler', () => randomMock.mockRejectedValue(new ApiError(500, null, 'boom'))],
    ['Leer', () => randomMock.mockRejectedValue(new ApiError(404, 'NO_QUOTES', 'none'))],
  ])('Struktur im Zustand %s (Req 8.4)', async (_name, setup) => {
    setup()
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.findAll('[aria-live="polite"]')).toHaveLength(1)
    const searchLinks = wrapper.findAll('a').filter((a) => a.attributes('href') === '/search')
    expect(searchLinks).toHaveLength(1)
    wrapper.unmount()
  })
})
